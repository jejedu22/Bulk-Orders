<?php

namespace App\Repository;

use App\Entity\JourDistrib;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

use App\Entity\Commande;
use App\Entity\product;
use App\Entity\LigneCommande;

use App\Entity\User;

/**
 * @method JourDistrib|null find($id, $lockMode = null, $lockVersion = null)
 * @method JourDistrib|null findOneBy(array $criteria, array $orderBy = null)
 * @method JourDistrib[]    findAll()
 * @method JourDistrib[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class JourDistribRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JourDistrib::class);
    }

    // /**
    //  * @return JourDistrib[] Returns an array of JourDistrib objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('j')
            ->andWhere('j.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('j.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */
    public function findAllOrder($order)
    {
        return $this->findBy(array(), array('date' => $order));
    }

    public function findAllUser(User $user)
    {
        
        return $this->createQueryBuilder('j')
            ->join('j.commandes','c')
            ->join('c.user','u')
            ->andWhere('u = :user')
            ->setParameter('user', $user)
            ->orderBy('j.date', 'DESC')
            ->getQuery()
            ->getResult()
            ;
    }
    public function findAllActive()
    {
        $now = date('Y-m-d');
        return $this->createQueryBuilder('j')
            ->andWhere('j.date >= :dateNow')
            ->setParameter('dateNow', $now)
            ->orderBy('j.date', 'ASC')
            ->getQuery()
            ->getResult()
            ;
    }
    public function findAllActiveByCommand()
    {               
        return $this->createQueryBuilder('j')
            ->join('j.commandes','c')
            ->andWhere('c.confirmed = true')
            ->andWhere('c.livree = false')
            ->orderBy('j.date', 'ASC')
            ->getQuery()
            ->getResult()
            ;
    }

    public function findConditionnement()
    {
        $entityManager = $this->getEntityManager();

        $query = $entityManager->createQuery(
            'SELECT c.id, p.id, j.total, SUM(p.conditionnement) as total_commande
            FROM App\Entity\JourDistrib j
            INNER JOIN j.commandes c
            INNER JOIN c.ligneCommandes lc
            INNER JOIN lc.product p
            GROUP BY c.id, j.total, p.id'
        );

        return $query->getResult();
    }

    public function findConditionnementProducts( $jourDistribId, $productId )
    {
        $entityManager = $this->getEntityManager();

        $query = $entityManager->createQuery(
            'SELECT SUM(p.conditionnement*lc.quantite) as conditionnement
            FROM App\Entity\JourDistrib j
            INNER JOIN j.commandes c
            INNER JOIN c.ligneCommandes lc
            INNER JOIN lc.product p
            WHERE j.id = :jourDistribId
            AND p.id = :productId
            GROUP BY p.id'
            )
            ->setParameter('jourDistribId', $jourDistribId)
            ->setParameter('productId', $productId);
            
            return $query->getResult();
    }
        
    public function recap($jourDistrib)
    {
            return $this->createQueryBuilder('j')
            ->select('p.id, p.nom, p.conditionnement, p.unit, SUM(lc.quantite) as total, cat.id as categoryId, cat.nom as categoryNom, cat.icon as categoryIcon, CASE WHEN cat.id IS NULL THEN 1 ELSE 0 END AS HIDDEN sansCategorie')
            ->join('j.commandes','c')
            ->join('c.ligneCommandes','lc')
            ->join('lc.product','p')
            ->leftJoin('p.category','cat')
            ->where('c.confirmed = true')
            ->andWhere('j = :jourDistrib')
            ->andWhere('j.closed = false')
            ->groupBy('p.id, cat.id')
            ->orderBy('sansCategorie', 'ASC')
            ->addOrderBy('cat.position', 'ASC')
            ->addOrderBy('cat.nom', 'ASC')
            ->addOrderBy('p.nom', 'ASC')
            ->setParameter('jourDistrib', $jourDistrib)
            ->getQuery()
            ->getResult()
            ;
    }

    public function export($jourDistrib)
    {
            return $this->createQueryBuilder('j')
            ->select('c.id, u.nom, u.prenom, c.commentaire, p.nom as produit, p.conditionnement, p.unit, lc.quantite, p.prixInit, p.prixFinal, c.livree, c.confirmed')
            ->join('j.commandes','c')
            ->join('c.user','u')
            ->join('c.ligneCommandes','lc')
            ->join('lc.product','p')
            ->andWhere('j = :jourDistrib')
            ->setParameter('jourDistrib', $jourDistrib)
            ->getQuery()
            ->getResult()
            ;
    }

    public function findCommande($user, $jourDistrib)
    {
        return $this->createQueryBuilder('j')
        ->select('j')
        ->join('j.commandes','c')
        ->join('c.user', 'u')
        ->andWhere('j = :jourDistrib')
        ->andWhere('u = :user')
        ->setParameter('jourDistrib', $jourDistrib)
        ->setParameter('user', $user)
        ->getQuery()
        ->getResult()
        ;
    }
    /*
    public function findOneBySomeField($value): ?JourDistrib
    {
        return $this->createQueryBuilder('j')
            ->andWhere('j.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
