<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: "payments")]
class Payment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\Column(type: "string", length: 100, unique: true)]
    private string $referenceId;

    #[ORM\Column(type: "bigint")]
    private int $amount;

    #[ORM\Column(type: "string", length: 20)]
    private string $status;

    #[ORM\Column(type: "datetime")]
    private \DateTime $createdAt;

    // Getter & Setter

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReferenceId(): string
    {
        return $this->referenceId;
    }

    public function setReferenceId(string $referenceId): self
    {
        $this->referenceId = $referenceId;
        return $this;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTime $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function markProcessing(): void
{
    if (!in_array($this->status, ['CREATED', 'FAILED'])) {
        throw new \Exception('Invalid transition to PROCESSING');
    }

    $this->status = 'PROCESSING';
}

    public function markSuccess(): void
    {
        if ($this->status !== 'PROCESSING') {
            throw new \Exception('Invalid transition to SUCCESS');
        }
        $this->status = 'SUCCESS';
    }

    public function markFailed(): void
    {
        if ($this->status !== 'PROCESSING') {
            throw new \Exception('Invalid transition to FAILED');
        }
        $this->status = 'FAILED';
    }

}