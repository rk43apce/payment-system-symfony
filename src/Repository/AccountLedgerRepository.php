<?php

namespace App\Repository;

use App\Entity\AccountLedger;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AccountLedgerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountLedger::class);
    }

    public function getBalance(int $userId): int
    {
        $result = $this->createQueryBuilder('l')
            ->select('SUM(CASE WHEN l.type = :credit THEN l.amount ELSE -l.amount END) AS balance')
            ->where('l.user = :userId')
            ->setParameter('userId', $userId)
            ->setParameter('credit', AccountLedger::TYPE_CREDIT)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    public function getHistory(int $userId): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByIdempotencyKey(string $key): ?AccountLedger
    {
        return $this->findOneBy(['idempotencyKey' => $key]);
    }
}
