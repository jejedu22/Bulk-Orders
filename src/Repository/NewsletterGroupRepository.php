<?php

namespace App\Repository;

use App\Entity\NewsletterGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method NewsletterGroup|null find($id, $lockMode = null, $lockVersion = null)
 * @method NewsletterGroup[]    findAll()
 */
class NewsletterGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsletterGroup::class);
    }

    /**
     * @return NewsletterGroup[]
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }

    /**
     * Nombre de membres de chaque groupe, sans charger les membres.
     *
     * @return array<int, int> id du groupe => nombre de membres
     */
    public function countMembers(): array
    {
        $counts = [];
        foreach ($this->createQueryBuilder('g')
            ->select('g.id AS id, COUNT(u.id) AS members')
            ->leftJoin('g.users', 'u')
            ->groupBy('g.id')
            ->getQuery()
            ->getArrayResult() as $row) {
            $counts[(int) $row['id']] = (int) $row['members'];
        }

        return $counts;
    }
}
