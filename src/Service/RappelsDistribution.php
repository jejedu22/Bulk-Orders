<?php

namespace App\Service;

use App\Entity\Commande;
use App\Entity\InscriptionBenevole;
use App\Entity\JourDistrib;
use App\Repository\JourDistribRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rappels de la distribution, envoyés la veille (automatiquement par la
 * commande app:distribution:rappels, ou par un administrateur) :
 *  - aux clients dont la commande est validée et pas encore remise, avec
 *    leur créneau ou, à défaut, une invitation à le choisir ;
 *  - aux bénévoles inscrits, avec leurs postes.
 * Chacun ne reçoit le rappel qu'une fois.
 */
class RappelsDistribution
{
    private $entityManager;
    private $mailSender;
    private $distribution;

    public function __construct(EntityManagerInterface $entityManager, MailSender $mailSender, Distribution $distribution)
    {
        $this->entityManager = $entityManager;
        $this->mailSender = $mailSender;
        $this->distribution = $distribution;
    }

    /**
     * @return Commande[]
     */
    public function clientsARappeler(JourDistrib $jour): array
    {
        return array_values(array_filter($jour->getCommandes()->toArray(), function (Commande $commande) {
            return $commande->getConfirmed() && !$commande->getLivree() && null === $commande->getRappelEnvoyeLe();
        }));
    }

    /**
     * Inscriptions à rappeler, regroupées par bénévole (clé = id de l'utilisateur).
     *
     * @return array<int, InscriptionBenevole[]>
     */
    public function benevolesARappeler(JourDistrib $jour): array
    {
        $parBenevole = [];
        foreach ($jour->getPostes() as $poste) {
            foreach ($poste->getInscriptions() as $inscription) {
                if (null === $inscription->getRappelEnvoyeLe()) {
                    $parBenevole[$inscription->getUser()->getId()][] = $inscription;
                }
            }
        }

        return $parBenevole;
    }

    public function aEnvoyer(JourDistrib $jour): int
    {
        return \count($this->clientsARappeler($jour)) + \count($this->benevolesARappeler($jour));
    }

    /**
     * @return array{sent: int, failed: string[]}
     */
    public function envoyer(JourDistrib $jour): array
    {
        $sent = 0;
        $failed = [];
        $maintenant = new \DateTimeImmutable();
        $date = $jour->getDateDistribution();

        foreach ($this->clientsARappeler($jour) as $commande) {
            $ok = $this->mailSender->sendTemplate(
                (string) $commande->getUser()->getMail(),
                sprintf('Rappel : distribution du %s', $this->jour($date)),
                'emails/rappel_retrait.html.twig',
                [
                    'commande' => $commande,
                    'jour' => $jour,
                    'creneau_url' => $this->mailSender->absoluteUrl('commande_creneau', ['id' => $commande->getId()]),
                ]
            );
            if ($ok) {
                $commande->setRappelEnvoyeLe($maintenant);
                ++$sent;
            } else {
                $failed[] = (string) $commande->getUser()->getMail();
            }
        }

        foreach ($this->benevolesARappeler($jour) as $inscriptions) {
            $user = $inscriptions[0]->getUser();
            $ok = $this->mailSender->sendTemplate(
                (string) $user->getMail(),
                sprintf('Rappel : vous êtes bénévole le %s', $this->jour($date)),
                'emails/rappel_benevole.html.twig',
                [
                    'user' => $user,
                    'jour' => $jour,
                    'inscriptions' => $inscriptions,
                    'benevolat_url' => $this->mailSender->absoluteUrl('benevolat_index'),
                ]
            );
            if ($ok) {
                foreach ($inscriptions as $inscription) {
                    $inscription->setRappelEnvoyeLe($maintenant);
                }
                ++$sent;
            } else {
                $failed[] = (string) $user->getMail();
            }
        }
        $this->entityManager->flush();

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * @return Commande[] commandes validées sans créneau
     */
    public function sansCreneau(JourDistrib $jour): array
    {
        return array_values(array_filter($jour->getCommandes()->toArray(), function (Commande $commande) {
            return $commande->isCreneauAChoisir();
        }));
    }

    /**
     * Relance les clients qui n'ont pas encore choisi de créneau.
     *
     * @return array{sent: int, failed: string[]}
     */
    public function relancerSansCreneau(JourDistrib $jour): array
    {
        $sent = 0;
        $failed = [];
        foreach ($this->sansCreneau($jour) as $commande) {
            $ok = $this->mailSender->sendTemplate(
                (string) $commande->getUser()->getMail(),
                'Choisissez votre créneau de retrait',
                'emails/choix_creneau.html.twig',
                [
                    'commande' => $commande,
                    'jour' => $jour,
                    'limite' => $this->distribution->dateLimite($jour),
                    'creneau_url' => $this->mailSender->absoluteUrl('commande_creneau', ['id' => $commande->getId()]),
                ]
            );
            if ($ok) {
                ++$sent;
            } else {
                $failed[] = (string) $commande->getUser()->getMail();
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Ventes dont la distribution a lieu demain (ou aujourd'hui, si le rappel
     * de la veille n'a pas pu partir).
     *
     * @return JourDistrib[]
     */
    public function ventesARappeler(?\DateTimeInterface $maintenant = null): array
    {
        $aujourdhui = ($maintenant ? \DateTimeImmutable::createFromInterface($maintenant) : new \DateTimeImmutable())->setTime(0, 0);
        $demain = $aujourdhui->modify('+1 day');

        /** @var JourDistribRepository $repository */
        $repository = $this->entityManager->getRepository(JourDistrib::class);
        $jours = $repository->createQueryBuilder('j')
            ->where('COALESCE(j.dateLivraison, j.date) IN (:dates)')
            ->setParameter('dates', [$aujourdhui->format('Y-m-d'), $demain->format('Y-m-d')])
            ->getQuery()
            ->getResult();

        return array_values(array_filter($jours, function (JourDistrib $jour) {
            return $jour->hasCreneaux() || !$jour->getPostes()->isEmpty();
        }));
    }

    private function jour(\DateTimeInterface $date): string
    {
        $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, null, null, 'EEEE d MMMM');

        return (string) $formatter->format($date);
    }
}
