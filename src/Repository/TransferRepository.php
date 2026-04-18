<?php

namespace App\Repository;

use App\Entity\Transfer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transfer>
 */
class TransferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transfer::class);
    }

    public function findBySenderId(int $senderId): array
    {
        return $this->findBy(['sender' => $senderId]);
    }

    public function findByRecipientId(int $recipientId): array
    {
        return $this->findBy(['recipient' => $recipientId]);
    }

    public function findByIdempotencyKey(string $key): ?Transfer
    {
        return $this->findOneBy(['idempotencyKey' => $key]);
    }
}
