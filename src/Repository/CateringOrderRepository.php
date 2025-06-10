<?php

namespace App\Repository;

use App\Entity\CateringOrder;
use App\Entity\CateringOrderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\UuidInterface;

/**
 * @extends ServiceEntityRepository<CateringOrder>
 *
 * @method CateringOrder|null find($id, $lockMode = null, $lockVersion = null)
 * @method CateringOrder|null findOneBy(array $criteria, array $orderBy = null)
 * @method CateringOrder[]    findAll()
 * @method CateringOrder[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CateringOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CateringOrder::class);
    }

    private function createQueryFilterBuilder(?UuidInterface $user, ?CateringOrderStatus $status): QueryBuilder
    {
        $qb = $this->createQueryBuilder('o');
        if (!is_null($user)) {
            $qb->andWhere('o.orderer = :orderer');
            $qb->setParameter('orderer', $user);
        }
        if (!is_null($status)) {
            $qb->andWhere('o.status = :status');
            $qb->setParameter('status', $status);
        }
        return $qb;
    }

    public function queryOrders(?UuidInterface $user = null, ?CateringOrderStatus $status = null): array
    {
        return $this->createQueryFilterBuilder($user, $status)
            ->addOrderBy('o.status', 'ASC')
            ->addOrderBy('o.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countOrders(?UuidInterface $user = null, ?CateringOrderStatus $status = null): int
    {
        return $this->createQueryFilterBuilder($user, $status)
            ->select('count(o)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findRecentOrdersByUser(UuidInterface $user, int $limit = 10): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.orderer = :orderer')
            ->setParameter('orderer', $user)
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getTotalSpentByUser(UuidInterface $user): int
    {
        $result = $this->createQueryBuilder('o')
            ->select('SUM(
                (SELECT SUM(pos.price * pos.quantity) 
                 FROM App\Entity\CateringOrderPosition pos 
                 WHERE pos.order = o)
            ) as total')
            ->andWhere('o.orderer = :orderer')
            ->andWhere('o.status = :status')
            ->setParameter('orderer', $user)
            ->setParameter('status', CateringOrderStatus::Paid)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }
}
