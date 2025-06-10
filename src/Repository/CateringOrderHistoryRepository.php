<?php

namespace App\Repository;

use App\Entity\CateringOrderHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CateringOrderHistory>
 *
 * @method CateringOrderHistory|null find($id, $lockMode = null, $lockVersion = null)
 * @method CateringOrderHistory|null findOneBy(array $criteria, array $orderBy = null)
 * @method CateringOrderHistory[]    findAll()
 * @method CateringOrderHistory[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CateringOrderHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CateringOrderHistory::class);
    }
}
