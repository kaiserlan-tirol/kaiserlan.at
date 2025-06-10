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
        return $this->findBy(['active' => true], ['sortIndex' => 'ASC']);
    }

    public function findByProductCode(string $productCode): ?CateringProduct
    {
        return $this->findOneBy(['productCode' => $productCode]);
    }

    public function findIncludedInFlat(): array
    {
        return $this->findBy(['active' => true, 'includedInFlat' => true], ['sortIndex' => 'ASC']);
    }

    public function findPaidProducts(): array
    {
        return $this->findBy(['active' => true, 'includedInFlat' => false], ['sortIndex' => 'ASC']);
    }
}
