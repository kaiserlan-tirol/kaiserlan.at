<?php

namespace App\Repository;

use App\Entity\Ticket;
use App\Entity\ShopOrderPositionAddon;
use App\Service\TicketState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\UuidInterface;

/**
 * @extends ServiceEntityRepository<Ticket>
 *
 * @method Ticket|null find($id, $lockMode = null, $lockVersion = null)
 * @method Ticket|null findOneBy(array $criteria, array $orderBy = null)
 * @method Ticket[]    findAll()
 * @method Ticket[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    public function findOneByRedeemer(UuidInterface $uuid): ?Ticket
    {
        return $this->findOneBy(['redeemer' => $uuid]);
    }

    public function findOneByCode(string $code): ?Ticket
    {
        return $this->findOneBy(['code' => $code]);
    }

    public function countRedeemed(): int
    {
        return $this->createQueryBuilder('t')
            ->select('count(t)')
            ->where('t.redeemedAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countPunched(): int
    {
        return $this->createQueryBuilder('t')
            ->select('count(t)')
            ->where('t.punchedAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countInvalid(): int
    {
        return $this->createQueryBuilder('t')
            ->select('count(t)')
            ->orWhere('t.redeemedAt IS NULL AND t.punchedAt IS NOT NULL')
            ->orWhere('t.redeemedAt IS NULL AND t.redeemer IS NOT NULL')
            ->orWhere('t.redeemedAt IS NOT NULL AND t.redeemer IS NULL')
            ->orWhere('t.createdAt > t.redeemedAt')
            ->orWhere('t.redeemedAt > t.punchedAt')
            ->orWhere('t.createdAt > t.punchedAt')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get all Ticket which are in the state or in a later state
     * @param TicketState $state
     * @param int|null $addonFilter Filter by addon ID
     * @return Ticket[]
     */
    public function findByState(TicketState $state, ?int $addonFilter = null): array
    {
        $qb = $this->createQueryBuilder('t');
        
        // Apply state filter
        switch ($state) {
            case TicketState::PUNCHED:
                $qb->andWhere('t.punchedAt IS NOT NULL');
                break;
            case TicketState::REDEEMED:
                $qb->andWhere('t.redeemedAt IS NOT NULL');
                break;
            case TicketState::NEW:
                break;
        }
        
        // Apply addon filter if specified
        if ($addonFilter !== null) {
            $qb->join('t.shopOrderPosition', 'sop')
               ->join('sop.addons', 'addon')
               ->andWhere('addon.addon = :addonId')
               ->setParameter('addonId', $addonFilter);
        }
        
        return $qb->getQuery()
            ->getResult();
    }

    /**
     * Get all existing catering QR codes for collision checking
     * @return array List of all cateringQrCode values that are already in use
     */
    public function findAllCateringQrCodes(): array
    {
        $result = $this->createQueryBuilder('t')
            ->select('t.cateringQrCode')
            ->where('t.cateringQrCode IS NOT NULL')
            ->getQuery()
            ->getArrayResult();
        
        // Extract just the codes into a flat array
        return array_map(
            function($item) { return $item['cateringQrCode']; }, 
            $result
        );
    }
}
