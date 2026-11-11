<?php

namespace App\Service;

use App\Entity\Commande;
use App\Entity\CreneauRetrait;
use App\Entity\InscriptionBenevole;
use App\Entity\JourDistrib;
use App\Entity\PosteBenevole;
use App\Entity\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Organisation de la distribution d'une vente : créneaux de retrait des
 * commandes et postes de bénévoles.
 *
 * Règles :
 *  - un client choisit son créneau une fois sa commande validée, et peut en
 *    changer jusqu'à la veille de la distribution à 18 h ; ensuite seul un
 *    administrateur peut le déplacer ;
 *  - un créneau complet n'accepte plus personne (sauf placement par un
 *    administrateur) ; la place est vérifiée sous verrou pour que deux
 *    personnes ne prennent pas la dernière en même temps ;
 *  - tout utilisateur connecté peut se proposer comme bénévole jusqu'au début
 *    du poste, et se désinscrire jusqu'à la même date limite que les clients.
 */
class Distribution
{
    public const HEURE_LIMITE = 18;

    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /** Veille de la distribution à 18 h. */
    public function dateLimite(JourDistrib $jour): \DateTimeImmutable
    {
        return $jour->getDateDistribution()->modify('-1 day')->setTime(self::HEURE_LIMITE, 0);
    }

    public function isModifiable(JourDistrib $jour, ?\DateTimeInterface $maintenant = null): bool
    {
        return $this->maintenant($maintenant) < $this->dateLimite($jour);
    }

    /** Le jour même de la distribution. */
    public function isJourJ(JourDistrib $jour, ?\DateTimeInterface $maintenant = null): bool
    {
        return $this->maintenant($maintenant)->format('Y-m-d') === $jour->getDateDistribution()->format('Y-m-d');
    }

    /**
     * Le client peut-il (encore) choisir ou changer son créneau ?
     */
    public function peutChoisir(Commande $commande, ?\DateTimeInterface $maintenant = null): bool
    {
        $jour = $commande->getJourDistrib();

        return $commande->getConfirmed() && !$commande->getLivree() && null !== $jour
            && $jour->hasCreneaux() && $this->isModifiable($jour, $maintenant);
    }

    /**
     * Réserve le créneau pour la commande. Sans $force (placement par un
     * administrateur), échoue si le créneau est complet.
     *
     * @return bool false si le créneau est complet
     */
    public function choisir(Commande $commande, CreneauRetrait $creneau, bool $force = false): bool
    {
        if ($creneau->getJourDistrib() !== $commande->getJourDistrib()) {
            throw new \InvalidArgumentException('Ce créneau appartient à une autre vente.');
        }
        if ($commande->getCreneau() === $creneau) {
            return true;
        }

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($commande, $creneau, $force) {
            // Verrou sur le créneau : les réservations concurrentes attendent celle-ci
            $em->lock($creneau, LockMode::PESSIMISTIC_WRITE);
            $reservees = $em->getRepository(CreneauRetrait::class)->countReservees($creneau);
            if (!$force && $reservees >= $creneau->getPlaces()) {
                return false;
            }
            $commande->setCreneau($creneau);
            $em->flush();

            return true;
        });
    }

    public function liberer(Commande $commande): void
    {
        $commande->setCreneau(null);
        $this->entityManager->flush();
    }

    /**
     * Crée des créneaux consécutifs de $duree minutes entre $de et $a
     * (« 09:00 », « 12:00 ») le jour de la distribution. Les créneaux qui
     * commencent à la même heure qu'un créneau existant sont ignorés.
     *
     * @return int nombre de créneaux créés
     */
    public function generer(JourDistrib $jour, string $de, string $a, int $duree, int $places): int
    {
        if ($duree < 5 || $places < 1) {
            throw new \InvalidArgumentException('Durée ou nombre de places invalide.');
        }
        $debut = $this->heure($jour, $de);
        $limite = $this->heure($jour, $a);
        $existants = array_map(function (CreneauRetrait $c) { return $c->getDebut()->format('H:i'); }, $jour->getCreneaux()->toArray());

        $crees = 0;
        while (($fin = $debut->modify(sprintf('+%d minutes', $duree))) <= $limite) {
            if (!\in_array($debut->format('H:i'), $existants, true)) {
                $this->entityManager->persist(new CreneauRetrait($jour, $debut, $fin, $places));
                ++$crees;
            }
            $debut = $fin;
        }
        $this->entityManager->flush();

        return $crees;
    }

    /**
     * Reprend les créneaux et les postes de bénévoles d'une autre vente, aux
     * mêmes heures, le jour de distribution de $cible.
     *
     * @return array{creneaux: int, postes: int}
     */
    public function copier(JourDistrib $source, JourDistrib $cible): array
    {
        $existants = array_map(function (CreneauRetrait $c) { return $c->getDebut()->format('H:i'); }, $cible->getCreneaux()->toArray());
        $creneaux = 0;
        foreach ($source->getCreneaux() as $creneau) {
            if (\in_array($creneau->getDebut()->format('H:i'), $existants, true)) {
                continue;
            }
            $this->entityManager->persist(new CreneauRetrait(
                $cible,
                $this->heure($cible, $creneau->getDebut()->format('H:i')),
                $this->heure($cible, $creneau->getFin()->format('H:i')),
                $creneau->getPlaces()
            ));
            ++$creneaux;
        }

        $postes = 0;
        foreach ($source->getPostes() as $poste) {
            $copie = new PosteBenevole($cible);
            $copie->setIntitule($poste->getIntitule())
                ->setDescription($poste->getDescription())
                ->setDebut($this->heure($cible, $poste->getDebut()->format('H:i')))
                ->setFin($this->heure($cible, $poste->getFin()->format('H:i')))
                ->setBesoin($poste->getBesoin());
            $this->entityManager->persist($copie);
            ++$postes;
        }
        $this->entityManager->flush();

        return ['creneaux' => $creneaux, 'postes' => $postes];
    }

    /** « 09:30 » le jour de la distribution. */
    public function heure(JourDistrib $jour, string $heure): \DateTimeImmutable
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $heure, $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
            throw new \InvalidArgumentException(sprintf('Heure invalide : %s', $heure));
        }

        return $jour->getDateDistribution()->setTime((int) $m[1], (int) $m[2]);
    }

    public function peutSInscrire(PosteBenevole $poste, ?\DateTimeInterface $maintenant = null): bool
    {
        return $this->maintenant($maintenant) < $poste->getDebut();
    }

    /**
     * Inscrit l'utilisateur sur le poste. Sans $force (ajout par un
     * administrateur), échoue si le poste est complet ou déjà commencé.
     *
     * @return bool false si le poste est complet ou commencé
     */
    public function inscrire(PosteBenevole $poste, User $user, bool $force = false): bool
    {
        if ($poste->isInscrit($user)) {
            return true;
        }
        if (!$force && !$this->peutSInscrire($poste)) {
            return false;
        }

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($poste, $user, $force) {
            $em->lock($poste, LockMode::PESSIMISTIC_WRITE);
            $inscrits = (int) $em->createQuery('SELECT COUNT(i.id) FROM App\Entity\InscriptionBenevole i WHERE i.poste = :poste')
                ->setParameter('poste', $poste)
                ->getSingleScalarResult();
            if (!$force && $inscrits >= $poste->getBesoin()) {
                return false;
            }
            $em->persist(new InscriptionBenevole($poste, $user));
            $em->flush();

            return true;
        });
    }

    public function peutSeDesinscrire(PosteBenevole $poste, ?\DateTimeInterface $maintenant = null): bool
    {
        return $this->isModifiable($poste->getJourDistrib(), $maintenant);
    }

    public function desinscrire(InscriptionBenevole $inscription): void
    {
        $inscription->getPoste()->removeInscription($inscription);
        $inscription->getUser()->removeInscriptionBenevole($inscription);
        $this->entityManager->remove($inscription);
        $this->entityManager->flush();
    }

    /** Inscrit sur au moins un poste de la vente. */
    public function isBenevole(User $user, JourDistrib $jour): bool
    {
        foreach ($jour->getPostes() as $poste) {
            if ($poste->isInscrit($user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Accès à la feuille de distribution : administrateurs, et bénévoles
     * inscrits sur la vente le jour même.
     */
    public function peutOuvrirFeuille(User $user, JourDistrib $jour, bool $admin): bool
    {
        return $admin || ($this->isJourJ($jour) && $this->isBenevole($user, $jour));
    }

    /**
     * Commandes validées de la vente, par créneau (clé = id du créneau, 0 = sans créneau).
     *
     * @return array<int, Commande[]>
     */
    public function commandesParCreneau(JourDistrib $jour): array
    {
        $groupes = [0 => []];
        foreach ($jour->getCreneaux() as $creneau) {
            $groupes[$creneau->getId()] = [];
        }
        foreach ($jour->getCommandes() as $commande) {
            if (!$commande->getConfirmed()) {
                continue;
            }
            $id = $commande->getCreneau() ? $commande->getCreneau()->getId() : 0;
            $groupes[$id][] = $commande;
        }
        foreach ($groupes as &$commandes) {
            usort($commandes, function (Commande $a, Commande $b) {
                return strcasecmp($a->getUser()->getNom() . ' ' . $a->getUser()->getPrenom(), $b->getUser()->getNom() . ' ' . $b->getUser()->getPrenom());
            });
        }

        return $groupes;
    }

    /**
     * Créneau à ouvrir par défaut le jour J : celui en cours, sinon le prochain,
     * sinon le dernier.
     */
    public function creneauCourant(JourDistrib $jour, ?\DateTimeInterface $maintenant = null): ?CreneauRetrait
    {
        $maintenant = $this->maintenant($maintenant);
        $dernier = null;
        foreach ($jour->getCreneaux() as $creneau) {
            if ($maintenant < $creneau->getFin()) {
                return $creneau;
            }
            $dernier = $creneau;
        }

        return $dernier;
    }

    private function maintenant(?\DateTimeInterface $maintenant): \DateTimeImmutable
    {
        return $maintenant ? \DateTimeImmutable::createFromInterface($maintenant) : new \DateTimeImmutable();
    }
}
