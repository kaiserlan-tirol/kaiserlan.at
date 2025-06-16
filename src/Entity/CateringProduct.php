<?php

namespace App\Entity;

use App\Repository\CateringProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CateringProductRepository::class)]
class CateringProduct
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private ?int $price = null;

    #[ORM\Column]
    private ?bool $active = null;

    /** @var Collection<int, ShopAddon> */
    #[ORM\ManyToMany(targetEntity: ShopAddon::class)]
    #[ORM\JoinTable(name: 'catering_product_addon')]
    private Collection $includedInAddons;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $productCode = null;

    #[ORM\Column(nullable: true)]
    private ?int $sortIndex = null;

    public function __construct()
    {
        $this->includedInAddons = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

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

    public function getPrice(): ?int
    {
        return $this->price;
    }

    public function setPrice(int $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    /**
     * @return Collection<int, ShopAddon>
     */
    public function getIncludedInAddons(): Collection
    {
        return $this->includedInAddons;
    }

    public function addIncludedInAddon(ShopAddon $addon): static
    {
        if (!$this->includedInAddons->contains($addon)) {
            $this->includedInAddons->add($addon);
        }

        return $this;
    }

    public function removeIncludedInAddon(ShopAddon $addon): static
    {
        $this->includedInAddons->removeElement($addon);

        return $this;
    }

    /**
     * Check if this product is included in any of the given addons
     */
    public function isIncludedInAnyAddon(array $userAddons): bool
    {
        foreach ($this->includedInAddons as $addon) {
            if (in_array($addon->getId(), array_map(fn($a) => $a->getId(), $userAddons))) {
                return true;
            }
        }
        return false;
    }

    /**
     * @deprecated Use getIncludedInAddons() instead
     */
    public function isIncludedInFlat(): bool
    {
        return !$this->includedInAddons->isEmpty();
    }

    /**
     * @deprecated Use addIncludedInAddon() instead
     */
    public function setIncludedInFlat(bool $includedInFlat): static
    {
        // Keep for backwards compatibility during migration
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

    public function getSortIndex(): ?int
    {
        return $this->sortIndex;
    }

    public function setSortIndex(?int $sortIndex): static
    {
        $this->sortIndex = $sortIndex;

        return $this;
    }
}
