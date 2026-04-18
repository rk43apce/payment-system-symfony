<?php

namespace App\Tests\Integration;

use App\Entity\Payment;
use App\Service\PaymentService;
use App\Service\RedisService;
use App\Tests\TestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PaymentIntegrationTest extends TestCase
{
    private PaymentService $paymentService;
    private RedisService $redisService;

    protected function setUp(): void
    {
        parent::setUp();

        // Get real services from container
        $this->paymentService = $this->container->get(PaymentService::class);
        $this->redisService = $this->container->get(RedisService::class);
    }

    public function testFullPaymentLifecycle(): void
    {
        $referenceId = 'integration_test_' . uniqid();
        $amount = 250;

        // 1. Create payment
        $payment = $this->paymentService->createPayment($referenceId, $amount);

        $this->assertInstanceOf(Payment::class, $payment);
        $this->assertEquals($referenceId, $payment->getReferenceId());
        $this->assertEquals($amount, $payment->getAmount());
        $this->assertEquals('CREATED', $payment->getStatus());

        // Verify in database
        $savedPayment = $this->entityManager->getRepository(Payment::class)
            ->findOneBy(['referenceId' => $referenceId]);
        $this->assertNotNull($savedPayment);
        $this->assertEquals($payment->getId(), $savedPayment->getId());

        // 2. Retrieve payment (should hit database first, then cache)
        $retrievedPayment = $this->paymentService->getPayment($referenceId);
        $this->assertEquals($payment->getId(), $retrievedPayment->getId());

        // 3. Verify caching works
        $cachedData = $this->redisService->getCachedPayment($referenceId);
        $this->assertNotNull($cachedData);
        $this->assertEquals($referenceId, $cachedData['reference_id']);
        $this->assertEquals($amount, $cachedData['amount']);

        // 4. Process payment
        $processedPayment = $this->paymentService->processPayment($referenceId);
        $this->assertEquals('PROCESSED', $processedPayment->getStatus());

        // Verify status updated in database
        $this->entityManager->refresh($savedPayment);
        $this->assertEquals('PROCESSED', $savedPayment->getStatus());
    }

    public function testIdempotentPaymentCreation(): void
    {
        $referenceId = 'idempotent_test_' . uniqid();
        $amount = 150;

        // Create first payment
        $payment1 = $this->paymentService->createPayment($referenceId, $amount);

        // Try to create again with same reference ID
        $payment2 = $this->paymentService->createPayment($referenceId, $amount);

        // Should return the same payment
        $this->assertEquals($payment1->getId(), $payment2->getId());
        $this->assertEquals($referenceId, $payment2->getReferenceId());

        // Verify only one payment exists in database
        $payments = $this->entityManager->getRepository(Payment::class)
            ->findBy(['referenceId' => $referenceId]);
        $this->assertCount(1, $payments);
    }

    public function testPaymentRetrievalFromCache(): void
    {
        $referenceId = 'cache_test_' . uniqid();
        $amount = 300;

        // Create payment
        $payment = $this->paymentService->createPayment($referenceId, $amount);

        // Clear Doctrine identity map to force database query
        $this->entityManager->clear();

        // First retrieval should hit database and cache
        $retrieved1 = $this->paymentService->getPayment($referenceId);
        $this->assertEquals($payment->getId(), $retrieved1->getId());

        // Second retrieval should hit cache
        $retrieved2 = $this->paymentService->getPayment($referenceId);
        $this->assertEquals($payment->getId(), $retrieved2->getId());

        // Verify cache contains data
        $cachedData = $this->redisService->getCachedPayment($referenceId);
        $this->assertNotNull($cachedData);
    }

    public function testPaymentNotFound(): void
    {
        $nonExistentRef = 'nonexistent_' . uniqid();

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Payment not found');

        $this->paymentService->getPayment($nonExistentRef);
    }

    public function testConcurrentPaymentProcessing(): void
    {
        $referenceId = 'concurrent_test_' . uniqid();
        $amount = 500;

        // Create payment
        $payment = $this->paymentService->createPayment($referenceId, $amount);

        // Simulate concurrent processing attempts
        $results = [];

        // Process multiple times (should be idempotent)
        for ($i = 0; $i < 3; $i++) {
            $results[] = $this->paymentService->processPayment($referenceId);
        }

        // All should return the same processed payment
        foreach ($results as $result) {
            $this->assertEquals($payment->getId(), $result->getId());
            $this->assertEquals('PROCESSED', $result->getStatus());
        }

        // Verify only one payment record exists
        $payments = $this->entityManager->getRepository(Payment::class)
            ->findBy(['referenceId' => $referenceId]);
        $this->assertCount(1, $payments);
        $this->assertEquals('PROCESSED', $payments[0]->getStatus());
    }
}