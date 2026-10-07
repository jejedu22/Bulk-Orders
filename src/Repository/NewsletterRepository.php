<?php

namespace App\Repository;

use App\Entity\Newsletter;
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
}
