<?php

namespace App\Service;

use App\Entity\AccountLedger;
use App\Enum\LedgerReferenceType;
use App\Enum\LedgerType;
use App\Repository\AccountLedgerRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;

class BalanceService
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private AccountLedgerRepository $ledgerRepository,
        private CacheInterface $cache
    ) {}

    public function getBalance(int $userId): array
    {
        $user = $this->userRepository->find($userId);
        if (!$user) {
            throw new \InvalidArgumentException('User not found');
        }

        $balance = $this->cache->get($this->cacheKey($userId), function ($item) use ($userId) {
            $item->expiresAfter(60);
            $item->beta(1.5);
            return $this->ledgerRepository->getBalance($userId);
        });

        return ['user_id' => $userId, 'balance' => $this->toDecimal($balance)];
    }

    public function addBalance(int $userId, int $amount, string $idempotencyKey): array
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }

        $user = $this->userRepository->find($userId);
        if (!$user) {
            throw new \InvalidArgumentException('User not found');
        }

        $existing = $this->ledgerRepository->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            $balance = $this->ledgerRepository->getBalance($userId);
            return ['user_id' => $userId, 'balance' => $this->toDecimal($balance)];
        }

        try {
            $this->em->beginTransaction();

            $entry = new AccountLedger();
            $entry->setUser($user)
                ->setAmount($amount)
                ->setType(LedgerType::CREDIT)
                ->setReferenceType(LedgerReferenceType::TOP_UP)
                ->setIdempotencyKey($idempotencyKey)
                ->setReferenceId(null)
                ->setCreatedAt(new \DateTimeImmutable());

            $this->em->persist($entry);
            $this->em->flush();
            $this->em->commit();

        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }

        $this->cache->delete($this->cacheKey($userId));

        return ['user_id' => $userId, 'balance' => $this->toDecimal($this->ledgerRepository->getBalance($userId))];
    }

    private function cacheKey(int $userId): string
    {
        return "user_balance_{$userId}";
    }

    private function toDecimal(int $paise): float
    {
        return round($paise / 100, 2);
    }
}
