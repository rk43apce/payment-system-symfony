<?php

namespace App\Tests\Service;

use App\Enum\TransferStatus;
use App\Repository\AccountLedgerRepository;
use App\Repository\TransferRepository;
use App\Repository\UserRepository;
use App\Service\RedisService;
use App\Service\TransferService;
use App\Tests\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;

class TransferServiceTest extends TestCase
{
    private TransferService $service;
    private RedisService $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = $this->createMock(RedisService::class);
        $this->redis->method('acquireLock')->willReturn(true);
        $this->redis->method('releaseLock')->willReturn(null);

        $this->service = new TransferService(
            $this->em,
            $this->redis,
            new NullLogger(),
            new ArrayAdapter(),
            $this->em->getRepository(\App\Entity\Transfer::class),
            $this->em->getRepository(\App\Entity\User::class),
            $this->em->getRepository(\App\Entity\AccountLedger::class)
        );
    }

    public function testTransferFundsSuccess(): void
    {
        $sender    = $this->createUser('Sender', 'sender@test.com');
        $recipient = $this->createUser('Recipient', 'recipient@test.com');

        $this->topUp($sender->getId(), 10000); // ₹100

        $transfer = $this->service->transferFunds(
            $sender->getId(),
            $recipient->getId(),
            5000,
            'txn-test-001-abc'
        );

        $this->assertSame(TransferStatus::COMPLETED, $transfer->getStatus());
        $this->assertSame(5000, $transfer->getAmount());
        $this->assertSame($sender->getId(), $transfer->getSender()->getId());
        $this->assertSame($recipient->getId(), $transfer->getRecipient()->getId());

        $ledger = $this->em->getRepository(\App\Entity\AccountLedger::class);
        $this->assertSame(5000, $ledger->getBalance($sender->getId()));
        $this->assertSame(5000, $ledger->getBalance($recipient->getId()));
    }

    public function testTransferIsIdempotent(): void
    {
        $sender    = $this->createUser('Sender', 'sender2@test.com');
        $recipient = $this->createUser('Recipient', 'recipient2@test.com');

        $this->topUp($sender->getId(), 20000);

        $key = 'txn-idem-test-001';

        $first  = $this->service->transferFunds($sender->getId(), $recipient->getId(), 5000, $key);
        $second = $this->service->transferFunds($sender->getId(), $recipient->getId(), 5000, $key);

        $this->assertSame($first->getId(), $second->getId());

        $ledger = $this->em->getRepository(\App\Entity\AccountLedger::class);
        $this->assertSame(15000, $ledger->getBalance($sender->getId()));
    }

    public function testTransferInsufficientFunds(): void
    {
        $sender    = $this->createUser('Sender', 'sender3@test.com');
        $recipient = $this->createUser('Recipient', 'recipient3@test.com');

        $this->topUp($sender->getId(), 1000); // ₹10

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient funds');

        $this->service->transferFunds($sender->getId(), $recipient->getId(), 5000, 'txn-insuf-001x');
    }

    public function testTransferSameUserThrows(): void
    {
        $user = $this->createUser('User', 'user@test.com');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sender and recipient must be different');

        $this->service->transferFunds($user->getId(), $user->getId(), 1000, 'txn-same-001xx');
    }

    public function testTransferBelowMinimumThrows(): void
    {
        $sender    = $this->createUser('Sender', 'sender4@test.com');
        $recipient = $this->createUser('Recipient', 'recipient4@test.com');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least');

        $this->service->transferFunds($sender->getId(), $recipient->getId(), 0, 'txn-zero-001xx');
    }

    public function testTransferAboveMaximumThrows(): void
    {
        $sender    = $this->createUser('Sender', 'sender5@test.com');
        $recipient = $this->createUser('Recipient', 'recipient5@test.com');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum');

        $this->service->transferFunds($sender->getId(), $recipient->getId(), 999_999_999_99, 'txn-max-001xxx');
    }

    public function testTransferUserNotFoundThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('account not found');

        $this->service->transferFunds(99999, 99998, 1000, 'txn-nouser-001x');
    }

    private function topUp(int $userId, int $amount): void
    {
        $user  = $this->em->find(\App\Entity\User::class, $userId);
        $entry = new \App\Entity\AccountLedger();
        $entry->setUser($user)
            ->setAmount($amount)
            ->setType(\App\Enum\LedgerType::CREDIT)
            ->setReferenceType(\App\Enum\LedgerReferenceType::TOP_UP)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($entry);
        $this->em->flush();
    }
}
