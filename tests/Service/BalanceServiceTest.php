<?php

namespace App\Tests\Service;

use App\Service\BalanceService;
use App\Tests\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class BalanceServiceTest extends TestCase
{
    private BalanceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new BalanceService(
            $this->em,
            $this->em->getRepository(\App\Entity\User::class),
            $this->em->getRepository(\App\Entity\AccountLedger::class),
            new ArrayAdapter()
        );
    }

    public function testGetBalanceReturnsZeroForNewUser(): void
    {
        $user   = $this->createUser();
        $result = $this->service->getBalance($user->getId());

        $this->assertSame($user->getId(), $result['user_id']);
        $this->assertSame(0.0, $result['balance']);
    }

    public function testGetBalanceUserNotFoundThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User not found');

        $this->service->getBalance(99999);
    }

    public function testAddBalanceCreditsCorrectly(): void
    {
        $user   = $this->createUser('Top Up User', 'topup@test.com');
        $result = $this->service->addBalance($user->getId(), 10050, 'topup-key-001xx');

        $this->assertSame(100.50, $result['balance']);
    }

    public function testAddBalanceIsIdempotent(): void
    {
        $user = $this->createUser('Idem User', 'idem@test.com');
        $key  = 'topup-idem-001xxx';

        $this->service->addBalance($user->getId(), 5000, $key);
        $result = $this->service->addBalance($user->getId(), 5000, $key);

        $this->assertSame(50.0, $result['balance']);
    }

    public function testAddBalanceZeroAmountThrows(): void
    {
        $user = $this->createUser('Zero User', 'zero@test.com');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be positive');

        $this->service->addBalance($user->getId(), 0, 'topup-zero-001xx');
    }

    public function testAddBalanceUserNotFoundThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User not found');

        $this->service->addBalance(99999, 1000, 'topup-nouser-001');
    }

    public function testGetBalanceReflectsMultipleTopUps(): void
    {
        $user = $this->createUser('Multi User', 'multi@test.com');

        $this->service->addBalance($user->getId(), 10000, 'topup-multi-001x');
        $this->service->addBalance($user->getId(), 5000, 'topup-multi-002x');

        $result = $this->service->getBalance($user->getId());
        $this->assertSame(150.0, $result['balance']);
    }
}
