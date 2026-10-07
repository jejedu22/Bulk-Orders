<?php

namespace App\Repository;

use App\Entity\Newsletter;
use App\Entity\NewsletterDelivery;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method NewsletterDelivery[] findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class NewsletterDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsletterDelivery::class);
    }

    /**
     * Newsletters reçues par l'utilisateur, de la plus récente à la plus ancienne.
     *
     * @return NewsletterDelivery[]
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('d')
            ->addSelect('n')
            ->join('d.newsletter', 'n')
            ->where('d.user = :user')
            ->setParameter('user', $user)
            ->orderBy('d.sentAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Destinataires d'une newsletter : échecs d'abord, puis par nom.
     *
     * @return NewsletterDelivery[]
     */
    public function findForNewsletter(Newsletter $newsletter): array
    {
        return $this->createQueryBuilder('d')
            ->addSelect('u')
            ->join('d.user', 'u')
            ->where('d.newsletter = :newsletter')
            ->setParameter('newsletter', $newsletter)
            ->addSelect('CASE WHEN d.error IS NULL THEN 1 ELSE 0 END AS HIDDEN ok')
            ->orderBy('ok', 'ASC')
            ->addOrderBy('d.test', 'ASC')
            ->addOrderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
