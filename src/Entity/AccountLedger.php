<?php

namespace App\Entity;

use App\Enum\LedgerReferenceType;
use App\Enum\LedgerType;
use App\Repository\AccountLedgerRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountLedgerRepository::class)]
#[ORM\Table(name: 'account_ledger')]
#[ORM\Index(columns: ['user_id'], name: 'idx_ledger_user')]
#[ORM\Index(columns: ['reference_id'], name: 'idx_ledger_reference')]
class AccountLedger
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(type: 'bigint')]
    private int $amount;

    #[ORM\Column(type: 'string', length: 10, enumType: LedgerType::class)]
    private LedgerType $type;

    #[ORM\Column(type: 'string', length: 20, enumType: LedgerReferenceType::class)]
    private LedgerReferenceType $referenceType;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $referenceId = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $idempotencyKey = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function getId(): ?int { return $this->id; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }

    public function getAmount(): int { return $this->amount; }
    public function setAmount(int $amount): static { $this->amount = $amount; return $this; }

    public function getType(): LedgerType { return $this->type; }
    public function setType(LedgerType $type): static { $this->type = $type; return $this; }

    public function getReferenceType(): LedgerReferenceType { return $this->referenceType; }
    public function setReferenceType(LedgerReferenceType $referenceType): static { $this->referenceType = $referenceType; return $this; }

    public function getReferenceId(): ?int { return $this->referenceId; }
    public function setReferenceId(?int $referenceId): static { $this->referenceId = $referenceId; return $this; }

    public function getIdempotencyKey(): ?string { return $this->idempotencyKey; }
    public function setIdempotencyKey(?string $idempotencyKey): static { $this->idempotencyKey = $idempotencyKey; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }
}
