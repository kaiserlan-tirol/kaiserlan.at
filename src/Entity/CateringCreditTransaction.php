<?php

namespace App\Entity;

use App\Repository\CateringCreditTransactionRepository;
use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity(repositoryClass: CateringCreditTransactionRepository::class)]
class CateringCreditTransaction
{
    public const TYPE_PAYMENT_RECEIVED = 'payment_received';
    public const TYPE_ORDER_PAYMENT = 'order_payment';
    public const TYPE_CREDIT_ADJUSTMENT = 'credit_adjustment';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private ?UuidInterface $user = null;

    #[ORM\Column]
    private ?DateTimeImmutable $timestamp = null;

    #[ORM\Column]
    private int $amount = 0;

    #[ORM\Column(length: 50)]
    private ?string $type = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: CateringOrder::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?CateringOrder $order = null;

    public function __construct()
    {
        $this->timestamp = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?UuidInterface
    {
        return $this->user;
    }

    public function setUser(?UuidInterface $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getTimestamp(): ?DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function setTimestamp(?DateTimeImmutable $timestamp): static
    {
        $this->timestamp = $timestamp;

        return $this;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getOrder(): ?CateringOrder
    {
        return $this->order;
    }

    public function setOrder(?CateringOrder $order): static
    {
        $this->order = $order;

        return $this;
    }
}
