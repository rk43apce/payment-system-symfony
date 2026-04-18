<?php

namespace App\Service;

use App\Entity\AccountLedger;
use App\Entity\Transfer;
use App\Enum\LedgerReferenceType;
use App\Enum\LedgerType;
use App\Enum\TransferStatus;
use App\Repository\AccountLedgerRepository;
use App\Repository\TransferRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;

class TransferService
{
    private const MAX_DEADLOCK_RETRIES = 3;
    private const MIN_AMOUNT           = 1;    // 1 paise minimum
    private const MAX_AMOUNT           = 10_000_000_00; // ₹1,00,00,000 (1 crore)

    public function __construct(
        private EntityManagerInterface $em,
        private RedisService $redis,
        private LoggerInterface $logger,
        private CacheInterface $cache,
        private TransferRepository $transferRepository,
        private UserRepository $userRepository,
        private AccountLedgerRepository $ledgerRepository
    ) {}

    public function transferFunds(int $senderId, int $recipientId, int $amount, string $idempotencyKey): Transfer
    {
        if ($senderId === $recipientId) {
            throw new \InvalidArgumentException('Sender and recipient must be different');
        }

        if ($amount < self::MIN_AMOUNT) {
            throw new \InvalidArgumentException('Transfer amount must be at least ₹0.01');
        }

        if ($amount > self::MAX_AMOUNT) {
            throw new \InvalidArgumentException('Transfer amount exceeds maximum allowed limit');
        }

        $existing = $this->transferRepository->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            $this->logger->info('Duplicate transfer request, returning existing', [
                'idempotency_key' => $idempotencyKey,
                'transfer_id'     => $existing->getId(),
            ]);
            return $existing;
        }

        if (!$this->redis->acquireLock('transfer_idem_' . $idempotencyKey, 30)) {
            throw new \RuntimeException('Transfer is already being processed. Please retry shortly.');
        }

        $attempt = 0;
        try {
            while (true) {
                try {
                    return $this->executeTransfer($senderId, $recipientId, $amount, $idempotencyKey);
                } catch (\Doctrine\DBAL\Exception\DeadlockException $e) {
                    $attempt++;
                    if ($attempt >= self::MAX_DEADLOCK_RETRIES) {
                        $this->logger->error('Transfer failed after max deadlock retries', [
                            'idempotency_key' => $idempotencyKey,
                            'attempts'        => $attempt,
                        ]);
                        throw new \RuntimeException('Transfer could not be completed due to high load. Please retry.');
                    }
                    $this->logger->warning('Deadlock detected, retrying transfer', [
                        'idempotency_key' => $idempotencyKey,
                        'attempt'         => $attempt,
                    ]);
                    usleep(50000 * $attempt);
                }
            }
        } finally {
            $this->redis->releaseLock('transfer_idem_' . $idempotencyKey);
        }
    }

    private function executeTransfer(int $senderId, int $recipientId, int $amount, string $idempotencyKey): Transfer
    {
        $this->em->beginTransaction();

        try {
            [$firstId, $secondId] = $senderId < $recipientId
                ? [$senderId, $recipientId]
                : [$recipientId, $senderId];

            $first  = $this->userRepository->findForUpdate($firstId);
            $second = $this->userRepository->findForUpdate($secondId);

            $sender    = $senderId === $firstId ? $first : $second;
            $recipient = $senderId === $firstId ? $second : $first;

            if (!$sender || !$recipient) {
                throw new \InvalidArgumentException('Sender or recipient account not found');
            }

            $existing = $this->transferRepository->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                $this->em->rollback();
                return $existing;
            }

            $senderBalance = $this->ledgerRepository->getBalance($senderId);
            if ($senderBalance < $amount) {
                throw new \RuntimeException('Insufficient funds');
            }

            $transfer = new Transfer();
            $transfer->setSender($sender)
                ->setRecipient($recipient)
                ->setAmount($amount)
                ->setStatus(TransferStatus::COMPLETED)
                ->setIdempotencyKey($idempotencyKey)
                ->setCreatedAt(new \DateTimeImmutable());

            $this->em->persist($transfer);
            $this->em->flush();

            $debit = new AccountLedger();
            $debit->setUser($sender)
                ->setAmount($amount)
                ->setType(LedgerType::DEBIT)
                ->setReferenceType(LedgerReferenceType::TRANSFER)
                ->setReferenceId($transfer->getId())
                ->setCreatedAt(new \DateTimeImmutable());

            $credit = new AccountLedger();
            $credit->setUser($recipient)
                ->setAmount($amount)
                ->setType(LedgerType::CREDIT)
                ->setReferenceType(LedgerReferenceType::TRANSFER)
                ->setReferenceId($transfer->getId())
                ->setCreatedAt(new \DateTimeImmutable());

            $this->em->persist($debit);
            $this->em->persist($credit);
            $this->em->flush();
            $this->em->commit();

            $this->cache->delete("user_balance_{$senderId}");
            $this->cache->delete("user_balance_{$recipientId}");

            $this->logger->info('Funds transferred successfully', [
                'idempotency_key' => $idempotencyKey,
                'sender_id'       => $senderId,
                'recipient_id'    => $recipientId,
                'amount'          => $amount,
                'transfer_id'     => $transfer->getId(),
            ]);

            return $transfer;

        } catch (\Throwable $e) {
            $this->em->rollback();
            $this->logger->error('Transfer execution failed', [
                'idempotency_key' => $idempotencyKey,
                'sender_id'       => $senderId,
                'recipient_id'    => $recipientId,
                'amount'          => $amount,
                'error'           => $e->getMessage(),
                'class'           => get_class($e),
            ]);
            throw $e;
        }
    }
}
