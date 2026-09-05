<?php

namespace App\Entity;

use App\Repository\UserTransactionRepository;
use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;

#[ORM\Table]
#[ORM\Index(name: 'user_transaction_user_idx', columns: ['user'])]
#[ORM\Entity(repositoryClass: UserTransactionRepository::class)]
class UserTransaction
{
    // Transaction types
    public const TYPE_INCOMING_PAYMENT = 'incoming_payment';
    public const TYPE_ORDER_PAYMENT = 'order_payment';
    public const TYPE_ORDER_REFUND = 'order_refund';
    public const TYPE_MANUAL_CREDIT_ADDITION = 'manual_credit_addition';
    public const TYPE_MANUAL_CREDIT_DEDUCTION = 'manual_credit_deduction';
    public const TYPE_SYSTEM_ADJUSTMENT = 'system_adjustment';

    // Transaction categories
    public const CATEGORY_CATERING = 'catering';
    public const CATEGORY_SHOP = 'shop';

    // Payment sources
    public const SOURCE_PAYPAL = 'paypal';
    public const SOURCE_REVOLUT = 'revolut';
    public const SOURCE_N26 = 'n26';
    public const SOURCE_SPARKASSE = 'sparkasse';
    public const SOURCE_MANUAL_ADMIN = 'manual_admin';
    public const SOURCE_SYSTEM = 'system';

    // Transaction status
    public const STATUS_PENDING = 'pending';
    public const STATUS_MATCHED = 'matched';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DUPLICATE = 'duplicate';

    // Match confidence
    public const CONFIDENCE_HIGH = 'high';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_LOW = 'low';
    public const CONFIDENCE_MANUAL = 'manual';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private ?UuidInterface $user = null;

    #[ORM\Column]
    private int $amount = 0; // Amount in cents

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    #[ORM\Column(length: 50)]
    private string $type = self::TYPE_INCOMING_PAYMENT;

    #[ORM\Column(length: 50)]
    private string $category = self::CATEGORY_CATERING;

    #[ORM\Column(length: 50)]
    private string $source = self::SOURCE_MANUAL_ADMIN;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalId = null; // PayPal transaction ID, bank reference, etc.

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reference = null; // Payment reference/note

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $referenceType = null; // 'shop_order', 'catering_order', 'payment', etc.

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $referenceId = null; // Order ID, payment ID, etc.

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null; // Human-readable description

    #[ORM\ManyToOne(targetEntity: CateringOrder::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?CateringOrder $cateringOrder = null;

    #[ORM\Column(nullable: true)]
    private ?int $shopOrderId = null; // We don't create FK to shop_order to avoid touching it

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $matchConfidence = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $processingNotes = null;

    #[ORM\Column]
    private bool $isDuplicate = false;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?UserTransaction $duplicateOfTransaction = null;

    #[ORM\Column]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $processedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $payerName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $payerEmail = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
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

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): static
    {
        $this->amount = $amount;
        return $this;
    }

    public function getAmountInEuros(): float
    {
        return $this->amount / 100.0;
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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
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

    public function getReferenceType(): ?string
    {
        return $this->referenceType;
    }

    public function setReferenceType(?string $referenceType): static
    {
        $this->referenceType = $referenceType;
        return $this;
    }

    public function getReferenceId(): ?string
    {
        return $this->referenceId;
    }

    public function setReferenceId(?string $referenceId): static
    {
        $this->referenceId = $referenceId;
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

    public function getCateringOrder(): ?CateringOrder
    {
        return $this->cateringOrder;
    }

    public function setCateringOrder(?CateringOrder $cateringOrder): static
    {
        $this->cateringOrder = $cateringOrder;
        return $this;
    }

    public function getShopOrderId(): ?int
    {
        return $this->shopOrderId;
    }

    public function setShopOrderId(?int $shopOrderId): static
    {
        $this->shopOrderId = $shopOrderId;
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

    public function isDuplicate(): bool
    {
        return $this->isDuplicate;
    }

    public function setIsDuplicate(bool $isDuplicate): static
    {
        $this->isDuplicate = $isDuplicate;
        return $this;
    }

    public function getDuplicateOfTransaction(): ?UserTransaction
    {
        return $this->duplicateOfTransaction;
    }

    public function setDuplicateOfTransaction(?UserTransaction $duplicateOfTransaction): static
    {
        $this->duplicateOfTransaction = $duplicateOfTransaction;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
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

    public function getPayerName(): ?string
    {
        return $this->payerName;
    }

    public function setPayerName(?string $payerName): static
    {
        $this->payerName = $payerName;
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

    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    public function markAsProcessed(): static
    {
        $this->status = self::STATUS_PROCESSED;
        $this->processedAt = new DateTimeImmutable();
        return $this;
    }

    public function markAsDuplicate(UserTransaction $originalTransaction): static
    {
        $this->isDuplicate = true;
        $this->status = self::STATUS_DUPLICATE;
        $this->duplicateOfTransaction = $originalTransaction;
        $this->processingNotes = 'Marked as duplicate of transaction #' . $originalTransaction->getId();
        return $this;
    }

    public function getTimestamp(): ?\DateTimeImmutable
    {
        // Prefer processedAt if set, else createdAt
        return $this->processedAt ?? $this->createdAt;
    }

    /**
     * Virtual accessor used by legacy Twig templates: transaction.order
     * Returns a CateringOrder entity if this transaction is linked to a catering order.
     * For shop orders we only store the numeric ID (shopOrderId) without FK; Twig templates
     * expect an object with an id property, so we provide a lightweight value object wrapper.
     * If neither is present returns null.
     */
    public function getOrder(): object|null
    {
        if ($this->cateringOrder) {
            return $this->cateringOrder; // has getId()
        }
        if ($this->shopOrderId) {
            // Anonymous value object with id property for Twig access
            return (object) ['id' => $this->shopOrderId];
        }
        return null;
    }

    /**
     * Convenience to get numeric order id regardless of type.
     */
    public function getOrderId(): ?int
    {
        if ($this->cateringOrder) {
            return $this->cateringOrder->getId();
        }
        return $this->shopOrderId;
    }

    /**
     * Return an array of textual representations of items contained in the linked catering order.
     * Empty array if no catering order is linked or it has no positions.
     * Each entry already contains the quantity (e.g. "2x Toast") via CateringOrderPosition::getText().
     */
    public function getOrderItems(): array
    {
        if (!$this->cateringOrder) {
            return [];
        }
        $items = [];
        foreach ($this->cateringOrder->getCateringOrderPositions() as $position) {
            // Defensive: ensure method exists and quantity/name present
            $items[] = $position->getText();
        }
        return $items;
    }

    /**
     * Inline, comma-separated list of order items for display in history tables.
     */
    public function getOrderItemsInline(): string
    {
        $items = $this->getOrderItems();
        if (empty($items)) {
            return '';
        }
        return implode(', ', $items);
    }
}
