<?php

namespace App\Controller;

use App\Entity\JourDistrib;
use App\Form\JourDistribType;
use App\Repository\NewsletterRepository;
use App\Repository\JourDistribRepository;
use App\Service\DemandePaiement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/jour/distrib')]
class JourDistribController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/', name: 'jour_distrib_index', methods: ['GET'])]
    public function index(JourDistribRepository $jourDistribRepository): Response
    {
        return $this->render('jour_distrib/index.html.twig', [
            'jour_distribs' => $jourDistribRepository->findBy([], ['date' => 'DESC']),
        ]);
    }

    #[Route('/new', name: 'jour_distrib_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $jourDistrib = new JourDistrib();
        $form = $this->createForm(JourDistribType::class, $jourDistrib);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

            $entityManager = $this->entityManager;
            $entityManager->persist($jourDistrib);
            $entityManager->flush();

            return $this->redirectToRoute('jour_distrib_index');
        }

        return $this->render('jour_distrib/new.html.twig', [
            'jour_distrib' => $jourDistrib,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'jour_distrib_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, JourDistrib $jourDistrib): Response
    {
        $form = $this->createForm(JourDistribType::class, $jourDistrib, [
            'edit' => true, 
            ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            return $this->redirectToRoute('jour_distrib_index');
        }

        return $this->render('jour_distrib/edit.html.twig', [
            'jour_distrib' => $jourDistrib,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Envoie aux clients le montant définitif de leur commande (avec le lien
     * de paiement en ligne si HelloAsso est configuré).
     */
    #[Route('/{id}/demande-paiement', name: 'jour_distrib_demande_paiement', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function demandePaiement(Request $request, JourDistrib $jourDistrib, DemandePaiement $demandePaiement): Response
    {
        if (!$this->isCsrfTokenValid('demande-paiement' . $jourDistrib->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'La demande a expiré, veuillez réessayer.');

            return $this->redirectToRoute('synthese_index', ['suivi' => 2]);
        }

        $result = $demandePaiement->send($jourDistrib);
        if ($result['sent'] > 0) {
            $this->addFlash('success', sprintf('%d demande%s de paiement envoyée%2$s.', $result['sent'], $result['sent'] > 1 ? 's' : ''));
        } elseif ([] === $result['failed']) {
            $this->addFlash('info', 'Aucune demande de paiement à envoyer pour cette vente.');
        }
        if ([] !== $result['failed']) {
            $this->addFlash('warning', 'E-mail non envoyé à : ' . implode(', ', $result['failed']) . '. Vous pourrez réessayer.');
        }

        return $this->redirectToRoute('synthese_index', ['suivi' => 2]);
    }

    #[Route('/{id}', name: 'jour_distrib_delete', methods: ['DELETE'])]
    public function delete(Request $request, JourDistrib $jourDistrib, NewsletterRepository $newsletterRepository): Response
    {
        if ($this->isCsrfTokenValid('delete'.$jourDistrib->getId(), $request->request->get('_token'))) {
            // Un brouillon réservé aux clients de cette vente partirait sinon à tous les abonnés
            $drafts = $newsletterRepository->findDraftsTargetingSale($jourDistrib);
            if ($drafts) {
                $this->addFlash('danger', sprintf(
                    'Des newsletters non envoyées sont réservées aux clients de cette vente (%s) : modifiez-les avant de supprimer la vente.',
                    implode(', ', array_map(function ($newsletter) { return '« ' . htmlspecialchars($newsletter->getSubject()) . ' »'; }, $drafts))
                ));

                return $this->redirectToRoute('jour_distrib_index');
            }

            $entityManager = $this->entityManager;
            $entityManager->remove($jourDistrib);
            $entityManager->flush();
        }

        return $this->redirectToRoute('jour_distrib_index');
    }
}
