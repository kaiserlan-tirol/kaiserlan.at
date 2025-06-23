<?php

namespace App\Repository;

use App\Entity\UserCateringCredit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\UuidInterface;

/**
 * @extends ServiceEntityRepository<UserCateringCredit>
 */
class UserCateringCreditRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserCateringCredit::class);
    }

    public function save(UserCateringCredit $credit): void
    {
        $this->getEntityManager()->persist($credit);
        $this->getEntityManager()->flush();
    }

    public function findByUser(UuidInterface $userUuid): ?UserCateringCredit
    {
        return $this->findOneBy(['user' => $userUuid]);
    }
}
