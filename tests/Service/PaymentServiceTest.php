<?php

namespace App\Tests\Service;

use App\Entity\Payment;
use App\Service\PaymentService;
use App\Service\RedisService;
use App\Tests\TestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PaymentServiceTest extends TestCase
{
    private PaymentService $paymentService;
    private $redisServiceMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redisServiceMock = $this->createMock(RedisService::class);

        $this->paymentService = new PaymentService(
            $this->entityManager,
            $this->redisServiceMock
        );
    }

    public function testCreatePaymentSuccess(): void
    {
        $referenceId = 'test_ref_' . uniqid();
        $amount = 500;

        $this->redisServiceMock->expects($this->once())
            ->method('getCachedPayment')
            ->with($referenceId)
            ->willReturn(null);

        $this->redisServiceMock->expects($this->once())
            ->method('cacheIdempotencyCheck')
            ->with($referenceId, false);

        $this->redisServiceMock->expects($this->once())
            ->method('acquireLock')
            ->willReturn(true);

        $this->redisServiceMock->expects($this->once())
            ->method('releaseLock');

        $this->redisServiceMock->expects($this->once())
            ->method('cachePayment')
            ->with($this->isInstanceOf(Payment::class));

        $payment = $this->paymentService->createPayment($referenceId, $amount);

        $this->assertInstanceOf(Payment::class, $payment);
        $this->assertEquals($referenceId, $payment->getReferenceId());
        $this->assertEquals($amount, $payment->getAmount());
        $this->assertEquals('CREATED', $payment->getStatus());
    }

    public function testCreatePaymentIdempotent(): void
    {
        $referenceId = 'existing_ref_' . uniqid();
        $amount = 500;

        // Create existing payment
        $existingPayment = $this->createPayment([
            'reference_id' => $referenceId,
            'amount' => $amount
        ]);

        $this->redisServiceMock->expects($this->once())
            ->method('getCachedPayment')
            ->with($referenceId)
            ->willReturn(null);

        $this->redisServiceMock->expects($this->once())
            ->method('cacheIdempotencyCheck')
            ->with($referenceId, true);

        $this->redisServiceMock->expects($this->once())
            ->method('cachePayment')
            ->with($existingPayment);

        $payment = $this->paymentService->createPayment($referenceId, $amount);

        $this->assertEquals($existingPayment->getId(), $payment->getId());
        $this->assertEquals($referenceId, $payment->getReferenceId());
    }

    public function testCreatePaymentLockFailure(): void
    {
        $referenceId = 'test_ref_' . uniqid();
        $amount = 500;

        $this->redisServiceMock->expects($this->once())
            ->method('getCachedPayment')
            ->with($referenceId)
            ->willReturn(null);

        $this->redisServiceMock->expects($this->once())
            ->method('cacheIdempotencyCheck')
            ->with($referenceId, false);

        $this->redisServiceMock->expects($this->once())
            ->method('acquireLock')
            ->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to acquire lock for payment processing');

        $this->paymentService->createPayment($referenceId, $amount);
    }

    public function testGetPaymentFromCache(): void
    {
        $referenceId = 'cached_ref_' . uniqid();
        $cachedData = [
            'reference_id' => $referenceId,
            'amount' => 300,
            'status' => 'CREATED',
            'created_at' => '2026-04-18 10:00:00'
        ];

        $this->redisServiceMock->expects($this->once())
            ->method('getCachedPayment')
            ->with($referenceId)
            ->willReturn($cachedData);

        $payment = $this->paymentService->getPayment($referenceId);

        $this->assertInstanceOf(Payment::class, $payment);
        $this->assertEquals($referenceId, $payment->getReferenceId());
        $this->assertEquals(300, $payment->getAmount());
        $this->assertEquals('CREATED', $payment->getStatus());
    }

    public function testGetPaymentFromDatabase(): void
    {
        $referenceId = 'db_ref_' . uniqid();

        // Create payment in database
        $dbPayment = $this->createPayment([
            'reference_id' => $referenceId,
            'amount' => 200
        ]);

        $this->redisServiceMock->expects($this->once())
            ->method('getCachedPayment')
            ->with($referenceId)
            ->willReturn(null);

        $this->redisServiceMock->expects($this->once())
            ->method('cachePayment')
            ->with($dbPayment);

        $payment = $this->paymentService->getPayment($referenceId);

        $this->assertEquals($dbPayment->getId(), $payment->getId());
        $this->assertEquals($referenceId, $payment->getReferenceId());
    }

    public function testGetPaymentNotFound(): void
    {
        $referenceId = 'nonexistent_ref_' . uniqid();

        $this->redisServiceMock->expects($this->once())
            ->method('getCachedPayment')
            ->with($referenceId)
            ->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Payment not found');

        $this->paymentService->getPayment($referenceId);
    }

    public function testProcessPaymentSuccess(): void
    {
        $referenceId = 'process_ref_' . uniqid();
        $amount = 1000;

        // Create payment
        $payment = $this->createPayment([
            'reference_id' => $referenceId,
            'amount' => $amount,
            'status' => 'CREATED'
        ]);

        $this->redisServiceMock->expects($this->once())
            ->method('acquireLock')
            ->with("payment_process:{$referenceId}")
            ->willReturn(true);

        $this->redisServiceMock->expects($this->once())
            ->method('releaseLock')
            ->with("payment_process:{$referenceId}");

        $this->redisServiceMock->expects($this->once())
            ->method('cachePayment')
            ->with($payment);

        $result = $this->paymentService->processPayment($referenceId);

        $this->assertInstanceOf(Payment::class, $result);
        $this->assertEquals('PROCESSED', $result->getStatus());
    }

    public function testProcessPaymentAlreadyProcessed(): void
    {
        $referenceId = 'processed_ref_' . uniqid();

        // Create already processed payment
        $payment = $this->createPayment([
            'reference_id' => $referenceId,
            'status' => 'PROCESSED'
        ]);

        $this->redisServiceMock->expects($this->once())
            ->method('acquireLock')
            ->with("payment_process:{$referenceId}")
            ->willReturn(true);

        $this->redisServiceMock->expects($this->once())
            ->method('releaseLock')
            ->with("payment_process:{$referenceId}");

        $result = $this->paymentService->processPayment($referenceId);

        $this->assertEquals($payment->getId(), $result->getId());
        $this->assertEquals('PROCESSED', $result->getStatus());
    }

    public function testProcessPaymentLockFailure(): void
    {
        $referenceId = 'lock_fail_ref_' . uniqid();

        $this->createPayment([
            'reference_id' => $referenceId,
            'status' => 'CREATED'
        ]);

        $this->redisServiceMock->expects($this->once())
            ->method('acquireLock')
            ->with("payment_process:{$referenceId}")
            ->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to acquire processing lock');

        $this->paymentService->processPayment($referenceId);
    }
}