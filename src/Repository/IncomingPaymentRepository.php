<?php

namespace App\Repository;

use App\Entity\IncomingPayment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\UuidInterface;

/**
 * @extends ServiceEntityRepository<IncomingPayment>
 */
class IncomingPaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IncomingPayment::class);
    }

    public function save(IncomingPayment $payment): void
    {
        $this->getEntityManager()->persist($payment);
        $this->getEntityManager()->flush();
    }

    public function findPendingPayments(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.status = :status')
            ->setParameter('status', IncomingPayment::STATUS_PENDING)
            ->orderBy('p.timestamp', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findUnmatchedPayments(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.status = :pending OR (p.status = :matched AND p.matchedUser IS NULL)')
            ->setParameter('pending', IncomingPayment::STATUS_PENDING)
            ->setParameter('matched', IncomingPayment::STATUS_MATCHED)
            ->orderBy('p.timestamp', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByUser(UuidInterface $userUuid): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.matchedUser = :user')
            ->setParameter('user', $userUuid)
            ->orderBy('p.timestamp', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByExternalId(string $externalId, string $source = null): ?IncomingPayment
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.externalId = :externalId')
            ->setParameter('externalId', $externalId);

        if ($source) {
            $qb->andWhere('p.source = :source')
               ->setParameter('source', $source);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findRecentByAmount(int $amount, \DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.amount = :amount')
            ->andWhere('p.timestamp >= :since')
            ->setParameter('amount', $amount)
            ->setParameter('since', $since)
            ->orderBy('p.timestamp', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByPayerEmail(string $email): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.payerEmail = :email')
            ->setParameter('email', $email)
            ->orderBy('p.timestamp', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getStatistics(): array
    {
        $result = $this->createQueryBuilder('p')
            ->select('p.status, COUNT(p.id) as count, SUM(p.amount) as total')
            ->groupBy('p.status')
            ->getQuery()
            ->getResult();

        $stats = [
            'pending' => ['count' => 0, 'total' => 0],
            'matched' => ['count' => 0, 'total' => 0],
            'processed' => ['count' => 0, 'total' => 0],
            'ignored' => ['count' => 0, 'total' => 0],
        ];

        foreach ($result as $row) {
            $stats[$row['status']] = [
                'count' => (int) $row['count'],
                'total' => (int) $row['total'],
            ];
        }

        return $stats;
    }

    public function findDuplicatePayments(): array
    {
        return $this->createQueryBuilder('p1')
            ->innerJoin(
                IncomingPayment::class,
                'p2',
                'WITH',
                'p1.amount = p2.amount AND p1.payerEmail = p2.payerEmail AND p1.id != p2.id AND ABS(TIMESTAMPDIFF(MINUTE, p1.timestamp, p2.timestamp)) <= 60'
            )
            ->orderBy('p1.timestamp', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all payments with pagination
     */
    public function findAllPaginated(int $page = 1, int $limit = 50): array
    {
        $offset = ($page - 1) * $limit;
        
        return $this->createQueryBuilder('p')
            ->orderBy('p.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find payments by status with pagination
     */
    public function findByStatusPaginated(string $status, int $page = 1, int $limit = 50): array
    {
        $offset = ($page - 1) * $limit;
        
        return $this->createQueryBuilder('p')
            ->where('p.status = :status')
            ->setParameter('status', $status)
            ->orderBy('p.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find unmatched payments with pagination
     */
    public function findUnmatchedPaginated(int $page = 1, int $limit = 50): array
    {
        $offset = ($page - 1) * $limit;
        
        return $this->createQueryBuilder('p')
            ->where('p.matchedUser IS NULL')
            ->andWhere('p.status != :ignored')
            ->setParameter('ignored', 'ignored')
            ->orderBy('p.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
