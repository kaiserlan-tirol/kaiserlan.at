<?php

namespace App\Entity;

use App\Repository\CateringOrderPositionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CateringOrderPositionRepository::class)]
class CateringOrderPosition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(fetch: 'EAGER', inversedBy: 'cateringOrderPositions')]
    #[ORM\JoinColumn(name: 'order_id', nullable: false)]
    private ?CateringOrder $order = null;

    #[ORM\Column]
    private ?int $price = null;

    #[ORM\Column]
    private ?int $quantity = null;

    #[ORM\Column(length: 255)]
    private ?string $productName = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $productCode = null;

    /** @var CateringProduct|null The product. Used for reference and counting. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CateringProduct $product = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getPrice(): ?int
    {
        return $this->price;
    }

    public function setPrice(int $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getProductName(): ?string
    {
        return $this->productName;
    }

    public function setProductName(string $productName): static
    {
        $this->productName = $productName;

        return $this;
    }

    public function getProductCode(): ?string
    {
        return $this->productCode;
    }

    public function setProductCode(?string $productCode): static
    {
        $this->productCode = $productCode;

        return $this;
    }

    public function getProduct(): ?CateringProduct
    {
        return $this->product;
    }

    public function setProduct(?CateringProduct $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function fillWithProduct(CateringProduct $product): static
    {
        $this->setProductName($product->getName());
        $this->setPrice($product->getPrice());
        $this->setProductCode($product->getProductCode());
        $this->setProduct($product);

        return $this;
    }

    public function getTotalPrice(): int
    {
        return ($this->price ?? 0) * ($this->quantity ?? 0);
    }

    public function getText(): string
    {
        $quantity = $this->quantity ?? 0;
        $name = $this->productName ?? 'Unknown Product';
        
        if ($quantity > 1) {
            return "{$quantity}x {$name}";
        }
        
        return $name;
    }
}
