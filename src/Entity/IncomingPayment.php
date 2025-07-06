<?php

namespace App\Entity;

use App\Repository\IncomingPaymentRepository;
use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity(repositoryClass: IncomingPaymentRepository::class)]
class IncomingPayment
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_MATCHED = 'matched';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_IGNORED = 'ignored';

    public const SOURCE_PAYPAL = 'paypal';
    public const SOURCE_BANK = 'bank_transfer';
    public const SOURCE_MANUAL = 'manual_admin';

    public const CONFIDENCE_HIGH = 'high';      // 90%+ match confidence
    public const CONFIDENCE_MEDIUM = 'medium';  // 50-90% match confidence
    public const CONFIDENCE_LOW = 'low';        // < 50% match confidence
    public const CONFIDENCE_MANUAL = 'manual';  // Manually assigned by admin

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    #[ORM\Column]
    private ?DateTimeImmutable $timestamp = null;

    #[ORM\Column(length: 50)]
    private string $source = self::SOURCE_MANUAL;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalId = null; // PayPal transaction ID, bank reference, etc.

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $reference = null; // Payment reference/note from payer

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $description = null; // Auto-generated or admin description

    #[ORM\Column(length: 320, nullable: true)]
    private ?string $payerEmail = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $payerName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $payerAccount = null; // IBAN, PayPal account, etc.

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?UuidInterface $matchedUser = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $matchConfidence = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $processingNotes = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null; // Store source-specific data

    #[ORM\Column]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?UuidInterface $processedBy = null; // Admin who processed it

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $processedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;
        return $this;
    }

    public function getAmountAsFloat(): float
    {
        return (float) $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;
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

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): static
    {
        $this->externalId = $externalId;
        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;
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

    public function getPayerEmail(): ?string
    {
        return $this->payerEmail;
    }

    public function setPayerEmail(?string $payerEmail): static
    {
        $this->payerEmail = $payerEmail;
        return $this;
    }

    public function getPayerName(): ?string
    {
        return $this->payerName;
    }

    public function setPayerName(?string $payerName): static
    {
        $this->payerName = $payerName;
        return $this;
    }

    public function getPayerAccount(): ?string
    {
        return $this->payerAccount;
    }

    public function setPayerAccount(?string $payerAccount): static
    {
        $this->payerAccount = $payerAccount;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getMatchedUser(): ?UuidInterface
    {
        return $this->matchedUser;
    }

    public function setMatchedUser(?UuidInterface $matchedUser): static
    {
        $this->matchedUser = $matchedUser;
        return $this;
    }

    public function getMatchConfidence(): ?string
    {
        return $this->matchConfidence;
    }

    public function setMatchConfidence(?string $matchConfidence): static
    {
        $this->matchConfidence = $matchConfidence;
        return $this;
    }

    public function getProcessingNotes(): ?string
    {
        return $this->processingNotes;
    }

    public function setProcessingNotes(?string $processingNotes): static
    {
        $this->processingNotes = $processingNotes;
        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getProcessedBy(): ?UuidInterface
    {
        return $this->processedBy;
    }

    public function setProcessedBy(?UuidInterface $processedBy): static
    {
        $this->processedBy = $processedBy;
        return $this;
    }

    public function getProcessedAt(): ?DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function setProcessedAt(?DateTimeImmutable $processedAt): static
    {
        $this->processedAt = $processedAt;
        return $this;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isMatched(): bool
    {
        return $this->status === self::STATUS_MATCHED;
    }

    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    public function isIgnored(): bool
    {
        return $this->status === self::STATUS_IGNORED;
    }

    public function getSourceLabel(): string
    {
        return match ($this->source) {
            self::SOURCE_PAYPAL => 'PayPal',
            self::SOURCE_BANK => 'Banküberweisung',
            self::SOURCE_MANUAL => 'Manuell',
            default => ucfirst($this->source),
        };
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Ausstehend',
            self::STATUS_MATCHED => 'Zugeordnet',
            self::STATUS_PROCESSED => 'Verarbeitet',
            self::STATUS_IGNORED => 'Ignoriert',
            default => ucfirst($this->status),
        };
    }

    public function getConfidenceLabel(): string
    {
        return match ($this->matchConfidence) {
            self::CONFIDENCE_HIGH => 'Hoch',
            self::CONFIDENCE_MEDIUM => 'Mittel',
            self::CONFIDENCE_LOW => 'Niedrig',
            self::CONFIDENCE_MANUAL => 'Manuell',
            default => 'Unbekannt',
        };
    }

    public function isManualMatch(): bool
    {
        return $this->matchConfidence === self::CONFIDENCE_MANUAL;
    }
}
