<?php

namespace App\Entity;

use App\Repository\CateringOrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity(repositoryClass: CateringOrderRepository::class)]
class CateringOrder implements OrderInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'uuid')]
    private ?UuidInterface $orderer = null;

    #[ORM\Column(type: 'integer', enumType: CateringOrderStatus::class)]
    private ?CateringOrderStatus $status = null;

    #[ORM\OneToMany(mappedBy: 'order', targetEntity: CateringOrderPosition::class, cascade: ['persist', 'remove'], fetch: 'EAGER', orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $cateringOrderPositions;

    #[ORM\OneToMany(mappedBy: 'order', targetEntity: CateringOrderHistory::class, cascade: ['persist', 'remove'], fetch: 'LAZY', orphanRemoval: true)]
    private Collection $cateringOrderHistory;

    public function __construct()
    {
        $this->cateringOrderPositions = new ArrayCollection();
        $this->cateringOrderHistory = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getOrderer(): ?UuidInterface
    {
        return $this->orderer;
    }

    public function setOrderer(UuidInterface $orderer): static
    {
        $this->orderer = $orderer;

        return $this;
    }

    public function getStatus(): ?CateringOrderStatus
    {
        return $this->status;
    }

    public function setStatus(CateringOrderStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isDead(): bool
    {
        return $this->status->isDead();
    }

    public function isRefunded(): bool
    {
        return $this->status->isRefunded();
    }

    public function isCanceled(): bool
    {
        return $this->status->isCanceled();
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }
    
    public function isPaymentSent(): bool
    {
        return $this->status->isPaymentSent();
    }

    /**
     * @return Collection<int, CateringOrderPosition>
     */
    public function getCateringOrderPositions(): Collection
    {
        return $this->cateringOrderPositions;
    }

    public function addCateringOrderPosition(CateringOrderPosition $cateringOrderPosition): static
    {
        if (!$this->cateringOrderPositions->contains($cateringOrderPosition)) {
            $this->cateringOrderPositions->add($cateringOrderPosition);
            $cateringOrderPosition->setOrder($this);
        }

        return $this;
    }

    public function removeCateringOrderPosition(CateringOrderPosition $cateringOrderPosition): static
    {
        if ($this->cateringOrderPositions->removeElement($cateringOrderPosition)) {
            // set the owning side to null (unless already changed)
            if ($cateringOrderPosition->getOrder() === $this) {
                $cateringOrderPosition->setOrder(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, CateringOrderHistory>
     */
    public function getCateringOrderHistory(): Collection
    {
        return $this->cateringOrderHistory;
    }

    public function addCateringOrderHistory(CateringOrderHistory $cateringOrderHistory): static
    {
        if (!$this->cateringOrderHistory->contains($cateringOrderHistory)) {
            $this->cateringOrderHistory->add($cateringOrderHistory);
            $cateringOrderHistory->setOrder($this);
        }

        return $this;
    }

    public function removeCateringOrderHistory(CateringOrderHistory $cateringOrderHistory): static
    {
        if ($this->cateringOrderHistory->removeElement($cateringOrderHistory)) {
            // set the owning side to null (unless already changed)
            if ($cateringOrderHistory->getOrder() === $this) {
                $cateringOrderHistory->setOrder(null);
            }
        }

        return $this;
    }

    public function calculateTotal(): int
    {
        $sum = 0;
        foreach ($this->cateringOrderPositions as $position) {
            $sum += ($position->getPrice() ?? 0) * ($position->getQuantity() ?? 0);
        }
        return $sum;
    }

    public function getTotalPrice(): int
    {
        return $this->calculateTotal();
    }

    public function isEmpty(): bool
    {
        return count($this->cateringOrderPositions) == 0;
    }

    public function getTotalItems(): int
    {
        $cnt = 0;
        foreach ($this->cateringOrderPositions as $position) {
            $cnt += $position->getQuantity() ?? 0;
        }
        return $cnt;
    }
}
