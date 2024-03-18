<?php

namespace App\Repository;

use App\Entity\IIIfManifestV2;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method IIIfManifestV2|null find($id, $lockMode = null, $lockVersion = null)
 * @method IIIfManifestV2|null findOneBy(array $criteria, array $orderBy = null)
 * @method IIIfManifestV2[]    findAll()
 * @method IIIfManifestV2[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class IIIfManifestV2Repository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IIIfManifestV2::class);
    }

    // /**
    //  * @return IIIfManifestV2[] Returns an array of IIIfManifest objects
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
    public function findOneBySomeField($value): ?IIIfManifestV2
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
