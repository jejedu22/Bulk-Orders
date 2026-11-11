<?php

namespace App\Controller;

use App\Entity\Commande;
use App\Entity\CreneauRetrait;
use App\Service\Distribution;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Choix du créneau de retrait par le client, une fois sa commande validée.
 */
class RetraitController extends AbstractController
{
    #[Route('/commande/{id}/creneau', name: 'commande_creneau', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function choisir(Request $request, Commande $commande, Distribution $distribution, EntityManagerInterface $entityManager): Response
    {
        if ($commande->getUser() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }
        $jour = $commande->getJourDistrib();
        if (!$jour->hasCreneaux()) {
            $this->addFlash('info', 'Pas de créneau de retrait pour cette vente.');

            return $this->redirectToRoute('commande_index');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('creneau' . $commande->getId(), $request->request->get('_token'))) {
                return $this->redirectToRoute('commande_creneau', ['id' => $commande->getId()]);
            }
            if (!$distribution->peutChoisir($commande)) {
                $this->addFlash('warning', $commande->getConfirmed()
                    ? 'Les créneaux ne peuvent plus être modifiés : contactez l\'équipe pour changer d\'horaire.'
                    : 'Vous pourrez choisir votre créneau dès que votre commande sera validée.');

                return $this->redirectToRoute('commande_creneau', ['id' => $commande->getId()]);
            }
            $creneau = $entityManager->find(CreneauRetrait::class, (int) $request->request->get('creneau'));
            if (null === $creneau || $creneau->getJourDistrib() !== $jour) {
                $this->addFlash('warning', 'Choisissez un créneau dans la liste.');
            } elseif ($distribution->choisir($commande, $creneau)) {
                $this->addFlash('success', sprintf('C\'est noté : retrait le %s de %s à %s.',
                    $jour->getDateDistribution()->format('d/m/Y'), $creneau->getDebut()->format('H:i'), $creneau->getFin()->format('H:i')));

                return $this->redirectToRoute('commande_creneau', ['id' => $commande->getId()]);
            } else {
                $this->addFlash('warning', sprintf('Le créneau %s vient d\'être complet : choisissez-en un autre.', $creneau->getLibelle()));
            }

            return $this->redirectToRoute('commande_creneau', ['id' => $commande->getId()]);
        }

        return $this->render('commande/creneau.html.twig', [
            'commande' => $commande,
            'jour' => $jour,
            'modifiable' => $distribution->peutChoisir($commande),
            'limite' => $distribution->dateLimite($jour),
        ]);
    }
}
