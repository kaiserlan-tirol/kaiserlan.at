<?php

namespace App\Repository;

use App\Entity\CateringProduct;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CateringProduct>
 *
 * @method CateringProduct|null find($id, $lockMode = null, $lockVersion = null)
 * @method CateringProduct|null findOneBy(array $criteria, array $orderBy = null)
 * @method CateringProduct[]    findAll()
 * @method CateringProduct[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CateringProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CateringProduct::class);
    }

    public function findActive(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.includedInAddons', 'a')
            ->addSelect('a')
            ->where('p.active = :active')
            ->setParameter('active', true)
            ->orderBy('p.sortIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByProductCode(string $productCode): ?CateringProduct
    {
        return $this->findOneBy(['productCode' => $productCode]);
    }

    /**
     * Find products that are included in any addon
     */
    public function findIncludedInFlat(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.includedInAddons', 'a')
            ->where('p.active = :active')
            ->andWhere('a.id IS NOT NULL')
            ->setParameter('active', true)
            ->orderBy('p.sortIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find products that are not included in any addon (paid products)
     */
    public function findPaidProducts(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.includedInAddons', 'a')
            ->where('p.active = :active')
            ->andWhere('a.id IS NULL')
            ->setParameter('active', true)
            ->orderBy('p.sortIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
