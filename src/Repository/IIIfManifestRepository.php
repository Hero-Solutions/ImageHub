<?php

namespace App\Repository;

use App\Entity\IIIfManifest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Persistence\ManagerRegistry;

/**
 * @method IIIfManifest|null find($id, $lockMode = null, $lockVersion = null)
 * @method IIIfManifest|null findOneBy(array $criteria, array $orderBy = null)
 * @method IIIfManifest[]    findAll()
 * @method IIIfManifest[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class IIIfManifestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IIIfManifest::class);
    }

    // /**
    //  * @return IIIfManifest[] Returns an array of IIIfManifest objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('i.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?IIIfManifest
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
