<?php

namespace App\Service;

use App\Entity\Adhesion;
use App\Entity\User;

/**
 * Saison d'adhésion (Paramètres → Adhésions) et valeurs proposées pour une
 * nouvelle adhésion ou un renouvellement.
 */
class AdhesionPeriode
{
    public const MOIS_DEBUT = 'adhesion_mois_debut';
    public const MONTANT = 'adhesion_montant';

    public const MOIS = [
        'janvier' => 1, 'février' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6,
        'juillet' => 7, 'août' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'décembre' => 12,
    ];

    private $options;

    public function __construct(OptionsSettings $options)
    {
        $this->options = $options;
    }

    /** Mois de début de la saison (1 = janvier : année civile). */
    public function moisDebut(): int
    {
        $mois = (int) $this->options->get(self::MOIS_DEBUT, '1');

        return $mois >= 1 && $mois <= 12 ? $mois : 1;
    }

    public function montantParDefaut(): ?float
    {
        $montant = $this->options->get(self::MONTANT, '');

        return '' === $montant ? null : (float) $montant;
    }

    /**
     * Saison contenant la date (aujourd'hui par défaut).
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} début et fin inclus
     */
    public function saison(?\DateTimeInterface $date = null): array
    {
        $date = $date ? \DateTimeImmutable::createFromInterface($date) : new \DateTimeImmutable('today');
        $mois = $this->moisDebut();
        $annee = (int) $date->format('Y');
        if ((int) $date->format('n') < $mois) {
            --$annee;
        }
        $debut = (new \DateTimeImmutable())->setDate($annee, $mois, 1)->setTime(0, 0);

        return [$debut, $debut->modify('+1 year -1 day')];
    }

    /** Libellé de la saison : « 2026 » ou « 2026-2027 ». */
    public function libelle(?\DateTimeInterface $date = null): string
    {
        [$debut, $fin] = $this->saison($date);

        return $debut->format('Y') === $fin->format('Y') ? $debut->format('Y') : $debut->format('Y') . '-' . $fin->format('Y');
    }

    /**
     * Adhésion pré-remplie pour cet utilisateur : la saison en cours, ou la
     * suite de son adhésion actuelle (renouvellement anticipé).
     */
    public function proposition(User $user): Adhesion
    {
        $aujourdhui = new \DateTimeImmutable('today');
        [$debut, $fin] = $this->saison($aujourdhui);

        $derniere = $user->getDerniereAdhesion();
        if ($derniere && $derniere->getDateFin()->format('Y-m-d') >= $aujourdhui->format('Y-m-d')) {
            $debut = $derniere->getDateFin()->setTime(0, 0)->modify('+1 day');
            $fin = $this->saison($debut)[1];
        }

        $adhesion = new Adhesion($user);
        $adhesion->setDateDebut($debut)->setDateFin($fin)->setMontant($this->montantParDefaut());

        return $adhesion;
    }
}
