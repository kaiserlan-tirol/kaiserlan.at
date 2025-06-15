<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
class ShopOrderPositionTicket extends ShopOrderPosition
{
    #[ORM\OneToOne(mappedBy: 'shopOrderPosition', cascade: ['persist'])]
    private ?Ticket $ticket = null;

    #[ORM\OneToMany(mappedBy: 'ticket', targetEntity: ShopOrderPositionAddon::class, cascade: ['persist', 'remove'])]
    private Collection $addons;

    public function __construct()
    {
        $this->addons = new ArrayCollection();
    }

    public function getTicket(): ?Ticket
    {
        return $this->ticket;
    }

    public function setTicket(?Ticket $ticket): static
    {
        // unset the owning side of the relation if necessary
        if ($ticket === null && $this->ticket !== null) {
            $this->ticket->setShopOrderPosition(null);
        }

        // set the owning side of the relation if necessary
        if ($ticket !== null && $ticket->getShopOrderPosition() !== $this) {
            $ticket->setShopOrderPosition($this);
        }

        $this->ticket = $ticket;

        return $this;
    }

    /**
     * @return Collection<int, ShopOrderPositionAddon>
     */
    public function getAddons(): Collection
    {
        return $this->addons;
    }

    public function addAddon(ShopOrderPositionAddon $addon): static
    {
        if (!$this->addons->contains($addon)) {
            $this->addons->add($addon);
            $addon->setTicket($this);
        }
        return $this;
    }

    public function removeAddon(ShopOrderPositionAddon $addon): static
    {
        if ($this->addons->removeElement($addon)) {
            if ($addon->getTicket() === $this) {
                $addon->setTicket(null);
            }
        }
        return $this;
    }

    public function getText(): ?string
    {
        if (empty($this->ticket)) {
            return "Ticket";
        } else {
            $nr = $this->ticket->getId();
            $addonCount = $this->addons->count();
            $baseText = "Ticket #{$nr}";
            if ($addonCount > 0) {
                $baseText .= " (+ {$addonCount} Addon" . ($addonCount > 1 ? 's' : '') . ")";
            }
            return $baseText;
        }
    }
}