<?php

namespace App\Controller;

use App\Entity\JourDistrib;
use App\Entity\PosteBenevole;
use App\Entity\User;
use App\Service\Distribution;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bénévolat : tout utilisateur connecté peut se proposer sur les postes des
 * prochaines distributions.
 */
#[Route('/benevolat')]
class BenevolatController extends AbstractController
{
    private $distribution;

    public function __construct(Distribution $distribution)
    {
        $this->distribution = $distribution;
    }

    #[Route('/', name: 'benevolat_index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $aujourdhui = new \DateTimeImmutable('today');

        // Prochaines distributions qui ont des postes de bénévoles
        $jours = $entityManager->getRepository(JourDistrib::class)->createQueryBuilder('j')
            ->innerJoin('j.postes', 'p')
            ->addSelect('p')
            ->where('COALESCE(j.dateLivraison, j.date) >= :today')
            ->setParameter('today', $aujourdhui->format('Y-m-d'))
            ->orderBy('j.date', 'DESC')
            ->getQuery()
            ->getResult();
        // La plus récente en premier
        usort($jours, function (JourDistrib $a, JourDistrib $b) { return $b->getDateDistribution() <=> $a->getDateDistribution(); });

        $mesPostes = [];
        foreach ($jours as $jour) {
            foreach ($jour->getPostes() as $poste) {
                if ($poste->isInscrit($user)) {
                    $mesPostes[] = $poste;
                }
            }
        }

        return $this->render('benevolat/index.html.twig', [
            'jours' => $jours,
            'mes_postes' => $mesPostes,
            'distribution' => $this->distribution,
        ]);
    }

    #[Route('/poste/{id}/inscription', name: 'benevolat_inscription', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function inscrire(Request $request, PosteBenevole $poste): Response
    {
        if ($this->isCsrfTokenValid('benevolat' . $poste->getId(), $request->request->get('_token'))) {
            /** @var User $user */
            $user = $this->getUser();
            if (!$this->distribution->peutSInscrire($poste)) {
                $this->addFlash('warning', 'Ce poste a déjà commencé.');
            } elseif ($this->distribution->inscrire($poste, $user)) {
                $this->addFlash('success', sprintf('Merci ! Vous êtes inscrit·e : %s, %s de %s à %s.',
                    htmlspecialchars($poste->getIntitule()),
                    $poste->getJourDistrib()->getDateDistribution()->format('d/m/Y'),
                    $poste->getDebut()->format('H:i'),
                    $poste->getFin()->format('H:i')));
            } else {
                $this->addFlash('warning', sprintf('Le poste « %s » est complet. Merci quand même !', htmlspecialchars($poste->getIntitule())));
            }
        }

        return $this->redirect($this->generateUrl('benevolat_index') . '#jour-' . $poste->getJourDistrib()->getId());
    }

    #[Route('/poste/{id}/desinscription', name: 'benevolat_desinscription', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function desinscrire(Request $request, PosteBenevole $poste): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $inscription = $poste->getInscription($user);
        if (null !== $inscription && $this->isCsrfTokenValid('benevolat' . $poste->getId(), $request->request->get('_token'))) {
            if ($this->distribution->peutSeDesinscrire($poste)) {
                $this->distribution->desinscrire($inscription);
                $this->addFlash('success', sprintf('Vous n\'êtes plus inscrit·e sur « %s ».', htmlspecialchars($poste->getIntitule())));
            } else {
                $this->addFlash('warning', 'La distribution est trop proche pour se désinscrire en ligne : prévenez l\'équipe directement.');
            }
        }

        return $this->redirect($this->generateUrl('benevolat_index') . '#jour-' . $poste->getJourDistrib()->getId());
    }
}
