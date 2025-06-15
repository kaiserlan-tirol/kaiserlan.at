<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
class ShopOrderPositionAddon extends ShopOrderPosition
{
    #[Assert\NotBlank]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $text = null;

    /** @var ShopAddon|null The addon. Just used for counting. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ShopAddon $addon = null;

    /** @var ShopOrderPositionTicket|null The ticket this addon is attached to */
    #[ORM\ManyToOne(targetEntity: ShopOrderPositionTicket::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?ShopOrderPositionTicket $ticket = null;

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(?string $text): self
    {
        $this->text = $text;

        return $this;
    }

    public function getAddon(): ?ShopAddon
    {
        return $this->addon;
    }

    public function setAddon(ShopAddon $addon): self
    {
        $this->addon = $addon;

        return $this;
    }

    public function getTicket(): ?ShopOrderPositionTicket
    {
        return $this->ticket;
    }

    public function setTicket(?ShopOrderPositionTicket $ticket): self
    {
        $this->ticket = $ticket;

        return $this;
    }

    public function fillWithAddon(ShopAddon $addon, ?ShopOrderPositionTicket $ticket = null): self
    {
        $this->setText($addon->getName());
        $this->setPrice($addon->getPrice());
        $this->setAddon($addon);
        if ($ticket) {
            $this->setTicket($ticket);
        }

        return $this;
    }
}