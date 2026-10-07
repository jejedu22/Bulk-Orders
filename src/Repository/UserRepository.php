<?php

namespace App\Repository;

use App\Entity\Commande;
use App\Entity\JourDistrib;
use App\Entity\Newsletter;
use App\Entity\NewsletterGroup;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @method User|null find($id, $lockMode = null, $lockVersion = null)
 * @method User|null findOneBy(array $criteria, array $orderBy = null)
 * @method User[]    findAll()
 * @method User[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', \get_class($user)));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * Destinataires des newsletters : abonnés, membres d'au moins un des
     * groupes s'il y en a (une seule fois chacun), et clients de la vente si
     * elle est indiquée. Sans groupe ni vente : tous les abonnés.
     *
     * @param iterable<NewsletterGroup> $groups
     *
     * @return User[]
     */
    public function findNewsletterSubscribers(iterable $groups = [], ?JourDistrib $jourDistrib = null): array
    {
        return $this->subscribersQuery($groups, $jourDistrib)
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param iterable<NewsletterGroup> $groups
     */
    public function countNewsletterSubscribers(iterable $groups = [], ?JourDistrib $jourDistrib = null): int
    {
        return (int) $this->subscribersQuery($groups, $jourDistrib)
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return User[]
     */
    public function findNewsletterRecipients(Newsletter $newsletter): array
    {
        return $this->findNewsletterSubscribers($newsletter->getGroups(), $newsletter->getJourDistrib());
    }

    public function countNewsletterRecipients(Newsletter $newsletter): int
    {
        return $this->countNewsletterSubscribers($newsletter->getGroups(), $newsletter->getJourDistrib());
    }

    private function subscribersQuery(iterable $groups, ?JourDistrib $jourDistrib = null): \Doctrine\ORM\QueryBuilder
    {
        $groups = \is_array($groups) ? $groups : iterator_to_array($groups, false);
        $qb = $this->createQueryBuilder('u')->andWhere('u.newsletter = true');
        if ($groups) {
            // Sous-requête : un membre de plusieurs groupes ne compte qu'une fois
            $members = $this->getEntityManager()->createQueryBuilder()
                ->select('m.id')
                ->from(NewsletterGroup::class, 'g')
                ->join('g.users', 'm')
                ->where('g IN (:groups)');
            $qb->andWhere($qb->expr()->in('u.id', $members->getDQL()))
                ->setParameter('groups', $groups);
        }
        if (null !== $jourDistrib) {
            $customers = $this->getEntityManager()->createQueryBuilder()
                ->select('IDENTITY(c.user)')
                ->from(Commande::class, 'c')
                ->where('c.jourDistrib = :jourDistrib');
            $qb->andWhere($qb->expr()->in('u.id', $customers->getDQL()))
                ->setParameter('jourDistrib', $jourDistrib);
        }

        return $qb;
    }

    // /**
    //  * @return User[] Returns an array of User objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('u.id', \SortDirection::Ascending)
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
