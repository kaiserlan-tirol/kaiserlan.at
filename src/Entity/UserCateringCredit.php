<?php

namespace App\Entity;

use App\Repository\UserCateringCreditRepository;
use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity(repositoryClass: UserCateringCreditRepository::class)]
class UserCateringCredit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private ?UuidInterface $user = null;

    #[ORM\Column]
    private int $amount = 0;

    #[ORM\Column]
    private ?DateTimeImmutable $updatedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    public function __construct()
    {
        $this->updatedAt = new DateTimeImmutable();
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
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function addCredit(int $amount): static
    {
        $this->amount += $amount;
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function deductCredit(int $amount): static
    {
        $this->amount = $this->amount - $amount; // Allow negative balance
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }
}
