<?php

namespace App\Repository;

use App\Entity\CreneauRetrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method CreneauRetrait|null find($id, $lockMode = null, $lockVersion = null)
 */
class CreneauRetraitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CreneauRetrait::class);
    }

    /**
     * Places réservées sur le créneau, comptées en base (et non dans la
     * collection chargée en mémoire, qui peut être périmée).
     */
    public function countReservees(CreneauRetrait $creneau): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'SELECT COUNT(c.id) FROM App\Entity\Commande c WHERE c.creneau = :creneau'
        )->setParameter('creneau', $creneau)->getSingleScalarResult();
    }
}
