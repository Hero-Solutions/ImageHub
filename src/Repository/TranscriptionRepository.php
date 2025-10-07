<?php

namespace App\Repository;

use App\Entity\Transcription;
use Doctrine\ORM\EntityRepository;

/**
 * @method Transcription|null find($id, $lockMode = null, $lockVersion = null)
 * @method Transcription|null findOneBy(array $criteria, array $orderBy = null)
 * @method Transcription[]    findAll()
 * @method Transcription[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TranscriptionRepository extends EntityRepository
{
    // /**
    //  * @return Transcription[] Returns an array of Transcription objects
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
    public function findOneBySomeField($value): ?Transcription
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
