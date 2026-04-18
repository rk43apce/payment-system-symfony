<?php

namespace App\Service;

use App\Entity\AccountLedger;
use App\Entity\Transfer;
use App\Entity\User;
use App\Repository\AccountLedgerRepository;
use App\Repository\TransferRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;

class TransferService
{
    public function __construct(
        private EntityManagerInterface $em,
        private RedisService $redis,
        private LoggerInterface $logger,
        private CacheInterface $cache,
        private TransferRepository $transferRepository,
        private UserRepository $userRepository,
        private AccountLedgerRepository $ledgerRepository
    ) {}

    private const MAX_DEADLOCK_RETRIES = 3;

    public function transferFunds(int $senderId, int $recipientId, int $amount, string $idempotencyKey): Transfer
    {
        if ($senderId === $recipientId) {
            throw new \InvalidArgumentException('Sender and recipient must be different');
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Transfer amount must be positive');
        }

        // Idempotency check before acquiring lock
        $existing = $this->transferRepository->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            $this->logger->info('Duplicate transfer request, returning existing', [
                'idempotency_key' => $idempotencyKey,
                'transfer_id'     => $existing->getId(),
            ]);
            return $existing;
        }

        $lockKey     = 'transfer_idem_' . $idempotencyKey;
        $lockAcquired = $this->redis->acquireLock($lockKey, 30);

        // If Redis is down, lock returns true (fail-open) but we still
        // rely on the DB unique constraint on idempotency_key as the hard guard
        if (!$lockAcquired) {
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
                    usleep(50000 * $attempt); // 50ms, 100ms backoff
                }
            }
        } finally {
            $this->redis->releaseLock($lockKey);
        }
    }

    private function executeTransfer(int $senderId, int $recipientId, int $amount, string $idempotencyKey): Transfer
    {
        if ($senderId === $recipientId) {
            throw new \InvalidArgumentException('Sender and recipient must be different');
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Transfer amount must be positive');
        }

        // Idempotency: return existing transfer if already processed
        $existing = $this->transferRepository->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            $this->logger->info('Duplicate transfer request, returning existing', [
                'idempotency_key' => $idempotencyKey,
                'transfer_id'     => $existing->getId(),
            ]);
            return $existing;
        }

        $lockKey = 'transfer_idem_' . $idempotencyKey;
        if (!$this->redis->acquireLock($lockKey, 30)) {
            throw new \RuntimeException('Transfer is already being processed. Please retry shortly.');
        }

        try {
            $this->em->beginTransaction();

            // Lock rows in consistent order to prevent deadlocks
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

            // Double-check idempotency inside transaction
            $existing = $this->transferRepository->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                $this->em->rollback();
                return $existing;
            }

            // Check sender has sufficient balance from ledger
            $senderBalance = $this->ledgerRepository->getBalance($senderId);
            if ($senderBalance < $amount) {
                throw new \RuntimeException('Insufficient funds');
            }

            // Create transfer record first to get the ID for ledger reference
            $transfer = new Transfer();
            $transfer->setSender($sender)
                ->setRecipient($recipient)
                ->setAmount($amount)
                ->setStatus('COMPLETED')
                ->setIdempotencyKey($idempotencyKey)
                ->setCreatedAt(new \DateTime());

            $this->em->persist($transfer);
            $this->em->flush(); // flush to get transfer ID

            // Write debit entry for sender
            $debit = new AccountLedger();
            $debit->setUser($sender)
                ->setAmount($amount)
                ->setType(AccountLedger::TYPE_DEBIT)
                ->setReferenceType(AccountLedger::REF_TRANSFER)
                ->setReferenceId($transfer->getId())
                ->setCreatedAt(new \DateTime());

            // Write credit entry for recipient
            $credit = new AccountLedger();
            $credit->setUser($recipient)
                ->setAmount($amount)
                ->setType(AccountLedger::TYPE_CREDIT)
                ->setReferenceType(AccountLedger::REF_TRANSFER)
                ->setReferenceId($transfer->getId())
                ->setCreatedAt(new \DateTime());

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
            $this->logger->error('Transfer failed', [
                'idempotency_key' => $idempotencyKey,
                'sender_id'       => $senderId,
                'recipient_id'    => $recipientId,
                'amount'          => $amount,
                'error'           => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            $this->redis->releaseLock($lockKey);
        }
    }
}
