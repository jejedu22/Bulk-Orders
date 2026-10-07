<?php

namespace App\Repository;

use App\Entity\JourDistrib;
use App\Entity\Newsletter;
use App\Entity\NewsletterGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Newsletter|null find($id, $lockMode = null, $lockVersion = null)
 * @method Newsletter[]    findAll()
 */
class NewsletterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Newsletter::class);
    }

    /**
     * Brouillons d'abord, puis les envois du plus récent au plus ancien.
     *
     * @return Newsletter[]
     */
    public function findAllForIndex(): array
    {
        return $this->createQueryBuilder('n')
            ->addSelect('CASE WHEN n.sentAt IS NULL THEN 0 ELSE 1 END AS HIDDEN sent')
            ->orderBy('sent', 'ASC')
            ->addOrderBy('n.sentAt', 'DESC')
            ->addOrderBy('n.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Brouillons qui ciblent ce groupe.
     *
     * @return Newsletter[]
     */
    public function findDraftsTargeting(NewsletterGroup $group): array
    {
        return $this->createQueryBuilder('n')
            ->join('n.groups', 'g')
            ->where('g = :group')
            ->andWhere('n.sentAt IS NULL')
            ->setParameter('group', $group)
            ->getQuery()
            ->getResult();
    }

    /**
     * Brouillons réservés aux clients de cette vente.
     *
     * @return Newsletter[]
     */
    public function findDraftsTargetingSale(JourDistrib $jourDistrib): array
    {
        return $this->findBy(['jourDistrib' => $jourDistrib, 'sentAt' => null]);
    }
}
