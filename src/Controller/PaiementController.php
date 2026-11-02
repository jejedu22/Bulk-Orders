<?php

namespace App\Controller;

use App\Entity\Commande;
use App\Repository\CommandeRepository;
use App\Service\CommandeConfirmation;
use App\Service\HelloAsso;
use App\Service\HelloAssoException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Paiement en ligne des commandes par HelloAsso.
 */
class PaiementController extends AbstractController
{
    private $entityManager;
    private $helloAsso;
    private $confirmation;

    public function __construct(EntityManagerInterface $entityManager, HelloAsso $helloAsso, CommandeConfirmation $confirmation)
    {
        $this->entityManager = $entityManager;
        $this->helloAsso = $helloAsso;
        $this->confirmation = $confirmation;
    }

    /**
     * Crée le paiement HelloAsso et envoie le client sur la page de paiement.
     */
    #[Route('/commande/{id}/paiement', name: 'commande_paiement', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function pay(Request $request, Commande $commande): Response
    {
        $this->denyUnlessOwner($commande);

        if (!$this->isCsrfTokenValid('paiement' . $commande->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'La demande de paiement a expiré, veuillez réessayer.');

            return $this->redirectAfterPayment($commande);
        }
        if ($commande->getconfirmed()) {
            $this->addFlash('info', 'Cette commande est déjà payée.');

            return $this->redirectAfterPayment($commande);
        }
        if (!$this->helloAsso->canPay($commande)) {
            $this->addFlash('warning', 'Le paiement en ligne n\'est pas disponible pour cette commande.');

            return $this->redirectAfterPayment($commande);
        }

        try {
            $redirectUrl = $this->helloAsso->createCheckout(
                $commande,
                $this->generateUrl('commande_paiement_retour', ['id' => $commande->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                $this->generateUrl('commande_paiement_erreur', ['id' => $commande->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                $this->generateUrl('commande_paiement_annule', ['id' => $commande->getId()], UrlGeneratorInterface::ABSOLUTE_URL)
            );
        } catch (HelloAssoException $e) {
            $this->addFlash('danger', 'Le paiement en ligne est momentanément indisponible. ' . $e->getMessage());

            return $this->redirectAfterPayment($commande);
        }
        $this->entityManager->flush();

        return $this->redirect($redirectUrl);
    }

    /**
     * Retour de la page de paiement HelloAsso.
     */
    #[Route('/commande/{id}/paiement/retour', name: 'commande_paiement_retour', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function back(Commande $commande, LoggerInterface $logger): Response
    {
        $this->denyUnlessOwner($commande);

        if (!$commande->getconfirmed()) {
            try {
                $orderId = $this->helloAsso->paidOrderId($commande);
            } catch (HelloAssoException $e) {
                $logger->error('HelloAsso : vérification du paiement de la commande {id} impossible : {message}', ['id' => $commande->getId(), 'message' => $e->getMessage()]);
                $orderId = null;
            }
            if (null !== $orderId) {
                $this->confirmation->confirmPaidOnline($commande, $orderId);
            }
        }

        if ($commande->getconfirmed()) {
            $this->addFlash('success', 'Merci ! Votre paiement a bien été reçu, un e-mail de confirmation vous a été envoyé.');
        } else {
            // Paiement encore en cours de traitement : la notification HelloAsso confirmera la commande
            $this->addFlash('info', 'Votre paiement est en cours de traitement. La commande sera confirmée dès sa validation par HelloAsso.');
        }

        return $this->redirectAfterPayment($commande);
    }

    #[Route('/commande/{id}/paiement/erreur', name: 'commande_paiement_erreur', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function error(Commande $commande): Response
    {
        $this->denyUnlessOwner($commande);
        $this->addFlash('danger', 'Le paiement n\'a pas abouti. Vous pouvez réessayer ou régler la commande lors de la distribution.');

        return $this->redirectAfterPayment($commande);
    }

    #[Route('/commande/{id}/paiement/annule', name: 'commande_paiement_annule', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function cancel(Commande $commande): Response
    {
        $this->denyUnlessOwner($commande);
        $this->addFlash('info', 'Paiement annulé : votre commande reste en attente de paiement.');

        return $this->redirectAfterPayment($commande);
    }

    /**
     * Notifications HelloAsso (URL à renseigner dans l'espace HelloAsso de
     * l'organisation). Le contenu de la notification n'est pas cru sur parole :
     * il sert seulement à retrouver la commande, dont le paiement est relu
     * auprès de l'API HelloAsso.
     */
    #[Route('/helloasso/notification', name: 'helloasso_notification', methods: ['POST'])]
    public function notification(Request $request, CommandeRepository $commandeRepository, LoggerInterface $logger): Response
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $commandeId = (int) ($payload['metadata']['commande'] ?? $payload['data']['metadata']['commande'] ?? 0);
        $commande = $commandeId > 0 ? $commandeRepository->find($commandeId) : null;
        if (null === $commande || $commande->getconfirmed() || null === $commande->getHelloassoCheckoutId()) {
            return new Response('', Response::HTTP_OK);
        }

        try {
            $orderId = $this->helloAsso->paidOrderId($commande);
        } catch (HelloAssoException $e) {
            $logger->error('HelloAsso : vérification du paiement de la commande {id} impossible : {message}', ['id' => $commande->getId(), 'message' => $e->getMessage()]);

            // HelloAsso renverra la notification
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE);
        }
        if (null !== $orderId) {
            $this->confirmation->confirmPaidOnline($commande, $orderId);
        }

        return new Response('', Response::HTTP_OK);
    }

    private function denyUnlessOwner(Commande $commande): void
    {
        if ($commande->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }

    /**
     * Retour sur « Ma dernière commande » s'il s'agit de la dernière commande
     * du client, sur l'historique de ses commandes sinon.
     */
    private function redirectAfterPayment(Commande $commande): Response
    {
        $last = $this->entityManager->getRepository(Commande::class)->findOneBy(['user' => $commande->getUser()], ['id' => 'desc']);

        return $last === $commande
            ? $this->redirectToRoute('commande_index')
            : $this->redirectToRoute('synthese_index', ['suivi' => 0]);
    }
}
