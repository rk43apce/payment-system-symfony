<?php

namespace App\Entity;

use App\Repository\AccountLedgerRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountLedgerRepository::class)]
#[ORM\Table(name: 'account_ledger')]
#[ORM\Index(columns: ['user_id'], name: 'idx_ledger_user')]
#[ORM\Index(columns: ['reference_id'], name: 'idx_ledger_reference')]
class AccountLedger
{
    const TYPE_CREDIT = 'CREDIT';
    const TYPE_DEBIT  = 'DEBIT';

    const REF_TOP_UP   = 'TOP_UP';
    const REF_TRANSFER = 'TRANSFER';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(type: 'bigint')]
    private int $amount;

    #[ORM\Column(type: 'string', length: 10)]
    private string $type; // CREDIT | DEBIT

    #[ORM\Column(type: 'string', length: 20)]
    private string $referenceType; // TOP_UP | TRANSFER

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $referenceId;

    #[ORM\Column(type: 'string', length: 64, unique: true, nullable: true)]
    private ?string $idempotencyKey = null; // transfer.id or null for top-up

    #[ORM\Column(type: 'datetime')]
    private \DateTime $createdAt;

    public function getId(): ?int { return $this->id; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }

    public function getAmount(): int { return $this->amount; }
    public function setAmount(int $amount): static { $this->amount = $amount; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }

    public function getReferenceType(): string { return $this->referenceType; }
    public function setReferenceType(string $referenceType): static { $this->referenceType = $referenceType; return $this; }

    public function getReferenceId(): ?int { return $this->referenceId; }
    public function setReferenceId(?int $referenceId): static { $this->referenceId = $referenceId; return $this; }

    public function getIdempotencyKey(): ?string { return $this->idempotencyKey; }
    public function setIdempotencyKey(?string $idempotencyKey): static { $this->idempotencyKey = $idempotencyKey; return $this; }

    public function getCreatedAt(): \DateTime { return $this->createdAt; }
    public function setCreatedAt(\DateTime $createdAt): static { $this->createdAt = $createdAt; return $this; }
}
