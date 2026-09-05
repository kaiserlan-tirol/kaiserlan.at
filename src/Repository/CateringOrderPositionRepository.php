<?php

namespace App\Repository;

use App\Entity\CateringOrderPosition;
use App\Entity\CateringProduct;
use App\Entity\CateringOrderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\UuidInterface;

/**
 * @extends ServiceEntityRepository<CateringOrderPosition>
 *
 * @method CateringOrderPosition|null find($id, $lockMode = null, $lockVersion = null)
 * @method CateringOrderPosition|null findOneBy(array $criteria, array $orderBy = null)
 * @method CateringOrderPosition[]    findAll()
 * @method CateringOrderPosition[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CateringOrderPositionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CateringOrderPosition::class);
    }

    /**
     * @param UuidInterface|null $uuid
     * @param CateringOrderStatus[] $statusFilter
     * @return array [product_id => quantity]
     */
    public function countOrderedProductsById(?UuidInterface $uuid = null, array $statusFilter = []): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $q = $qb->select('identity(op.product) as pid, SUM(op.quantity) as qty')
            ->from(CateringOrderPosition::class, 'op')
            ->groupBy('op.product')
            ->join('op.order', 'o');

        if (!empty($statusFilter)) {
            $q
                ->andWhere('o.status in (:status)')
                ->setParameter('status', $statusFilter);
        }
        if (!is_null($uuid)) {
            $q
                ->andWhere('o.orderer = :uuid')
                ->setParameter('uuid', $uuid);
        }
        return array_column($q->getQuery()->getArrayResult(), 'qty', 'pid');
    }

    /**
     * @param CateringProduct $product
     * @param UuidInterface|null $uuid
     * @param CateringOrderStatus[] $statusFilter
     * @return int
     */
    public function countOrderedProducts(CateringProduct $product, ?UuidInterface $uuid = null, array $statusFilter = []): int
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $q = $qb->select('SUM(op.quantity)')
            ->from(CateringOrderPosition::class, 'op')
            ->join('op.order', 'o')
            ->andWhere('op.product = :product')
            ->setParameter('product', $product);

        if (!empty($statusFilter)) {
            $q
                ->andWhere('o.status in (:status)')
                ->setParameter('status', $statusFilter);
        }
        if (!is_null($uuid)) {
            $q
                ->andWhere('o.orderer = :uuid')
                ->setParameter('uuid', $uuid);
        }
        return (int) ($q->getQuery()->getSingleScalarResult() ?? 0);
    }

    /**
     * Aggregates the ordered positions per product, splitting the quantity into items covered by a
     * flatrate (price 0) and items that were paid for.
     *
     * @param CateringOrderStatus[] $statusFilter
     * @return array [product_id => ['flat' => quantity, 'paid' => quantity, 'revenue' => sum in cents]]
     */
    public function getProductStatistics(array $statusFilter = []): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $q = $qb->select(
                'identity(op.product) as pid',
                'SUM(CASE WHEN op.price = 0 THEN op.quantity ELSE 0 END) as flat',
                'SUM(CASE WHEN op.price > 0 THEN op.quantity ELSE 0 END) as paid',
                'SUM(CASE WHEN op.price > 0 THEN op.price * op.quantity ELSE 0 END) as revenue'
            )
            ->from(CateringOrderPosition::class, 'op')
            ->join('op.order', 'o')
            ->andWhere('op.product IS NOT NULL')
            ->groupBy('op.product');

        if (!empty($statusFilter)) {
            $q
                ->andWhere('o.status in (:status)')
                ->setParameter('status', $statusFilter);
        }

        $result = [];
        foreach ($q->getQuery()->getArrayResult() as $row) {
            $result[$row['pid']] = [
                'flat' => (int) $row['flat'],
                'paid' => (int) $row['paid'],
                'revenue' => (int) $row['revenue'],
            ];
        }
        return $result;
    }

    /**
     * @param CateringOrderStatus[] $statusFilter
     * @return CateringOrderPosition[]
     */
    public function getOrderedProducts(array $statusFilter = []): array
    {
        return $this->createQueryBuilder('op')
            ->join('op.order', 'o')
            ->andWhere('o.status in (:status)')
            ->setParameter('status', $statusFilter)
            ->getQuery()
            ->getResult();
    }
}
