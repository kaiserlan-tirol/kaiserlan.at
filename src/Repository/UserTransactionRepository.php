<?php

namespace App\Repository;

use App\Entity\UserTransaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\UuidInterface;
use DateTimeImmutable;

/**
 * @extends ServiceEntityRepository<UserTransaction>
 */
class UserTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserTransaction::class);
    }

    public function save(UserTransaction $transaction): void
    {
        $this->getEntityManager()->persist($transaction);
        $this->getEntityManager()->flush();
    }

    /**
     * Find all transactions for a user
     */
    public function findByUser(UuidInterface $user): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findCateringPizzaTransactions(
        UuidInterface $user,
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): array {
        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.category = :category')
            ->andWhere('t.createdAt BETWEEN :from AND :to')
            ->andWhere('(t.description LIKE :pizza OR t.description LIKE :cancellation)')
            ->setParameter('user', $user)
            ->setParameter('category', UserTransaction::CATEGORY_CATERING)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('pizza', 'Pizza:%')
            ->setParameter('cancellation', 'Storno Pizza:%')
            ->orderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Calculate total catering balance for a user
     */
    public function calculateCateringBalance(UuidInterface $user): int
    {
        $result = $this->createQueryBuilder('t')
            ->select('SUM(t.amount) as total')
            ->andWhere('t.user = :user')
            ->andWhere('t.category = :category')
            ->setParameter('user', $user)
            ->setParameter('category', UserTransaction::CATEGORY_CATERING)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * Calculate total shop balance for a user
     */
    public function calculateShopBalance(UuidInterface $user): int
    {
        $result = $this->createQueryBuilder('t')
            ->select('SUM(t.amount) as total')
            ->andWhere('t.user = :user')
            ->andWhere('t.category = :category')
            ->setParameter('user', $user)
            ->setParameter('category', UserTransaction::CATEGORY_SHOP)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * Check for duplicate payments within the last 24 hours
     * Used to prevent duplicate payment processing
     */
    public function findDuplicatePayment(UuidInterface $user, int $amount, int $hours = 24): ?UserTransaction
    {
        $since = new DateTimeImmutable("-{$hours} hours");

        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.amount = :amount OR t.amount = :negative_amount')
            ->andWhere('t.type IN (:types)')
            ->andWhere('t.createdAt >= :since')
            ->setParameter('user', $user)
            ->setParameter('amount', $amount)
            ->setParameter('negative_amount', -$amount) // Also check for payments (negative amounts)
            ->setParameter('types', [
                UserTransaction::TYPE_INCOMING_PAYMENT,
                UserTransaction::TYPE_MANUAL_CREDIT_ADDITION,
                UserTransaction::TYPE_ORDER_PAYMENT // Include order payments for duplicate detection
            ])
            ->setParameter('since', $since)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find transactions by reference (order ID, payment reference, etc.)
     */
    public function findByReference(string $referenceType, string $referenceId): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.referenceType = :type')
            ->andWhere('t.referenceId = :id')
            ->setParameter('type', $referenceType)
            ->setParameter('id', $referenceId)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find recent transactions for a user
     */
    public function findRecentTransactions(UuidInterface $user, int $limit = 10): array
    {
        // Eager load catering order + positions to avoid N+1 when rendering order item summaries
        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->leftJoin('t.cateringOrder', 'o')->addSelect('o')
            ->leftJoin('o.cateringOrderPositions', 'p')->addSelect('p')
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find pending incoming payments that haven't been processed
     */
    public function findPendingIncomingPayments(): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.type = :type')
            ->andWhere('t.status = :status')
            ->setParameter('type', UserTransaction::TYPE_INCOMING_PAYMENT)
            ->setParameter('status', UserTransaction::STATUS_PENDING)
            ->orderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find transactions by bank reference (for matching incoming payments)
     */
    public function findByBankReference(string $bankReference): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.bankReference = :reference')
            ->setParameter('reference', $bankReference)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get transaction statistics for a user
     */
    public function getUserTransactionStats(UuidInterface $user): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select([
                't.type',
                't.category',
                'COUNT(t.id) as count',
                'SUM(t.amount) as total'
            ])
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->groupBy('t.type', 't.category')
            ->getQuery();

        return $qb->getResult();
    }

    /**
     * Find transactions by date range
     */
    public function findByDateRange(
        UuidInterface $user = null,
        DateTimeImmutable $from = null,
        DateTimeImmutable $to = null
    ): array {
        $qb = $this->createQueryBuilder('t');

        if ($user) {
            $qb->andWhere('t.user = :user')->setParameter('user', $user);
        }

        if ($from) {
            $qb->andWhere('t.createdAt >= :from')->setParameter('from', $from);
        }

        if ($to) {
            $qb->andWhere('t.createdAt <= :to')->setParameter('to', $to);
        }

        return $qb->orderBy('t.createdAt', 'DESC')->getQuery()->getResult();
    }
}
