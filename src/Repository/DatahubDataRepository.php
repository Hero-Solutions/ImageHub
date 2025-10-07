<?php

namespace App\Repository;

use App\Entity\DatahubData;
use Doctrine\ORM\EntityRepository;

/**
 * @method DatahubData|null find($id, $lockMode = null, $lockVersion = null)
 * @method DatahubData|null findOneBy(array $criteria, array $orderBy = null)
 * @method DatahubData[]    findAll()
 * @method DatahubData[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DatahubDataRepository extends EntityRepository
{
    public function findById($id): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.id = :val')
            ->setParameter('val', $id)
            ->getQuery()
            ->getResult()
        ;
    }
}
