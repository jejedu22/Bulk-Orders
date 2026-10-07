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
}
