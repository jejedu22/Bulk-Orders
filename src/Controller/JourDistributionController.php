<?php

namespace App\Controller;

use App\Entity\Commande;
use App\Entity\InscriptionBenevole;
use App\Entity\JourDistrib;
use App\Entity\User;
use App\Service\Distribution;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Feuille de distribution du jour J, par créneau de retrait. Ouverte aux
 * administrateurs, et aux bénévoles inscrits sur la vente le jour même.
 */
#[Route('/distribution')]
class JourDistributionController extends AbstractController
{
    private $entityManager;
    private $distribution;

    public function __construct(EntityManagerInterface $entityManager, Distribution $distribution)
    {
        $this->entityManager = $entityManager;
        $this->distribution = $distribution;
    }

    #[Route('/{id}', name: 'distribution_jour', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Request $request, JourDistrib $jour): Response
    {
        $this->denyUnlessAllowed($jour);

        $groupes = $this->distribution->commandesParCreneau($jour);
        $onglet = $request->query->has('creneau') ? (int) $request->query->get('creneau') : null;
        if (null === $onglet || !\array_key_exists($onglet, $groupes)) {
            $onglet = $this->ongletParDefaut($jour, $groupes);
        }

        $inscriptions = [];
        foreach ($jour->getPostes() as $poste) {
            foreach ($poste->getInscriptions() as $inscription) {
                $inscriptions[] = $inscription;
            }
        }

        return $this->render('distribution/jour.html.twig', [
            'jour' => $jour,
            'groupes' => $groupes,
            'onglet' => $onglet,
            'creneau' => $onglet ? $this->entityManager->find(\App\Entity\CreneauRetrait::class, $onglet) : null,
            'inscriptions' => $inscriptions,
            'jour_j' => $this->distribution->isJourJ($jour),
        ]);
    }

    /** Commande remise (ou annulation d'une remise cochée par erreur). */
    #[Route('/commande/{id}/remise', name: 'distribution_remise', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function remise(Request $request, Commande $commande): Response
    {
        $jour = $commande->getJourDistrib();
        $this->denyUnlessAllowed($jour);
        if ($commande->getConfirmed() && $this->isCsrfTokenValid('jour' . $jour->getId(), $request->request->get('_token'))) {
            $remise = !$commande->getLivree();
            $commande->setLivree($remise);
            foreach ($commande->getLigneCommandes() as $ligne) {
                $ligne->setLivree($remise);
            }
            $this->entityManager->flush();
            $this->addFlash($remise ? 'success' : 'info', sprintf('%s %s : commande %s.', htmlspecialchars($commande->getUser()->getPrenom()), htmlspecialchars($commande->getUser()->getNom()), $remise ? 'remise' : 'remise annulée'));
        }

        return $this->redirectToRoute('distribution_jour', ['id' => $jour->getId(), 'creneau' => $request->request->get('creneau', 0)]);
    }

    #[Route('/inscription/{id}/presence', name: 'distribution_presence', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function presence(Request $request, InscriptionBenevole $inscription): Response
    {
        $jour = $inscription->getPoste()->getJourDistrib();
        $this->denyUnlessAllowed($jour);
        if ($this->isCsrfTokenValid('jour' . $jour->getId(), $request->request->get('_token'))) {
            $inscription->setPresent(!$inscription->isPresent());
            $this->entityManager->flush();
        }

        return $this->redirect($this->generateUrl('distribution_jour', ['id' => $jour->getId(), 'creneau' => $request->request->get('creneau', 0)]) . '#benevoles');
    }

    /**
     * Créneau en cours (ou le prochain) ; une fois tous les créneaux passés,
     * le premier où des commandes restent à remettre.
     *
     * @param array<int, Commande[]> $groupes
     */
    private function ongletParDefaut(JourDistrib $jour, array $groupes): int
    {
        $reste = function (int $id) use ($groupes): bool {
            foreach ($groupes[$id] ?? [] as $commande) {
                if (!$commande->getLivree()) {
                    return true;
                }
            }

            return false;
        };
        $courant = $this->distribution->isJourJ($jour) ? $this->distribution->creneauCourant($jour) : $jour->getCreneaux()->first();
        if (!$courant) {
            return 0;
        }
        if (new \DateTimeImmutable() < $courant->getFin() || $reste($courant->getId())) {
            return $courant->getId();
        }
        foreach ($jour->getCreneaux() as $creneau) {
            if ($reste($creneau->getId())) {
                return $creneau->getId();
            }
        }

        return $reste(0) ? 0 : $courant->getId();
    }

    private function denyUnlessAllowed(JourDistrib $jour): void
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->distribution->peutOuvrirFeuille($user, $jour, $this->isGranted('ROLE_ADMIN'))) {
            throw $this->createAccessDeniedException('La feuille de distribution est réservée aux bénévoles inscrits, le jour de la distribution.');
        }
    }
}
