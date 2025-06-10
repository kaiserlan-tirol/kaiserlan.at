<?php

namespace App\Entity;

use App\Repository\CateringOrderHistoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CateringOrderHistoryRepository::class)]
class CateringOrderHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'cateringOrderHistory')]
    #[ORM\JoinColumn(nullable: false)]
    private ?CateringOrder $order = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $loggedAt = null;

    #[ORM\Column(type: 'string', enumType: CateringOrderHistoryAction::class)]
    private ?CateringOrderHistoryAction $action = null;

    #[ORM\Column(length: 1024)]
    private ?string $text = '';

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

    public function getLoggedAt(): ?\DateTimeImmutable
    {
        return $this->loggedAt;
    }

    public function setLoggedAt(\DateTimeImmutable $loggedAt): static
    {
        $this->loggedAt = $loggedAt;

        return $this;
    }

    public function getAction(): ?CateringOrderHistoryAction
    {
        return $this->action;
    }

    public function setAction(CateringOrderHistoryAction $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(string $text): static
    {
        $this->text = $text;

        return $this;
    }
}
