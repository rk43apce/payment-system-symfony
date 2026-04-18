<?php

namespace App\Service;

use App\Entity\Payment;
use Doctrine\ORM\EntityManagerInterface;
use App\Exception\PaymentNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PaymentService
{
    public function __construct(
        private EntityManagerInterface $em,
        private RedisService $redis
    ) {}

    public function createPayment(string $referenceId, int $amount): Payment
    {
        // 1. Check cache for idempotency first
        $cachedExists = $this->redis->getCachedIdempotencyCheck($referenceId);
        if ($cachedExists === true) {
            // Payment exists in cache, fetch from DB or cache
            $cachedPayment = $this->redis->getCachedPayment($referenceId);
            if ($cachedPayment) {
                return $this->hydratePaymentFromCache($cachedPayment);
            }
        }

        // 2. Idempotency check in database
        $existing = $this->em->getRepository(Payment::class)
            ->findOneBy(['referenceId' => $referenceId]);

        if ($existing) {
            // Cache the result for future requests
            $this->redis->cacheIdempotencyCheck($referenceId, true);
            $this->cachePayment($existing);
            return $existing;
        }

        // 3. Cache that payment doesn't exist (negative caching)
        $this->redis->cacheIdempotencyCheck($referenceId, false);

        // 4. Create payment
        $payment = new Payment();
        $payment->setReferenceId($referenceId);
        $payment->setAmount($amount);
        $payment->setStatus('CREATED');
        $payment->setCreatedAt(new \DateTime());

        // 5. Save
        $this->em->persist($payment);
        $this->em->flush();

        // 6. Cache the new payment
        $this->cachePayment($payment);

        return $payment;
    }

    public function processPayment(string $referenceId): Payment
    {
        // Try to acquire distributed lock to prevent concurrent processing
        $lockKey = "process_payment:{$referenceId}";
        if (!$this->redis->acquireLock($lockKey, 30)) {
            // Another process is already handling this payment
            // Return current status from cache or DB
            $cachedPayment = $this->redis->getCachedPayment($referenceId);
            if ($cachedPayment) {
                return $this->hydratePaymentFromCache($cachedPayment);
            }

            $payment = $this->em->getRepository(Payment::class)
                ->findOneBy(['referenceId' => $referenceId]);

            if (!$payment) {
                throw new \Exception('Payment not found');
            }

            return $payment;
        }

        try {
            $this->em->beginTransaction();

            $payment = $this->em->getRepository(Payment::class)
                ->findOneBy(['referenceId' => $referenceId]);

            if (!$payment) {
                throw new \Exception('Payment not found');
            }

            // ✅ If already processed → return directly (idempotent)
            if (in_array($payment->getStatus(), ['SUCCESS', 'FAILED'])) {
                return $payment;
            }

            // ✅ Only process if CREATED
            if ($payment->getStatus() === 'CREATED') {
                $payment->markProcessing();
                $this->em->flush();
            }

            // Simulate external payment processor call
            $success = random_int(0, 1);

            if ($success) {
                $payment->markSuccess();
            } else {
                $payment->markFailed();
            }

            $this->em->flush();
            $this->em->commit();

            // Update cache with new status
            $this->cachePayment($payment);

            return $payment;
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        } finally {
            // Always release the lock
            $this->redis->releaseLock($lockKey);
        }
    }

    public function getPayment(string $referenceId): Payment
    {
        // Try cache first
        $cachedPayment = $this->redis->getCachedPayment($referenceId);
        if ($cachedPayment) {
            return $this->hydratePaymentFromCache($cachedPayment);
        }

        // Fallback to database
        $payment = $this->em->getRepository(Payment::class)
            ->findOneBy(['referenceId' => $referenceId]);

        if (!$payment) {
            throw new NotFoundHttpException('Payment not found');
        }

        // Cache for future requests
        $this->cachePayment($payment);

        return $payment;
    }

    private function cachePayment(Payment $payment): void
    {
        $paymentData = [
            'id' => $payment->getId(),
            'reference_id' => $payment->getReferenceId(),
            'amount' => $payment->getAmount(),
            'status' => $payment->getStatus(),
            'created_at' => $payment->getCreatedAt()->format('Y-m-d H:i:s'),
        ];

        $this->redis->cachePayment($payment->getReferenceId(), $paymentData, 300); // 5 minutes TTL
    }

    private function hydratePaymentFromCache(array $data): Payment
    {
        // Create a payment object from cached data
        // Note: This is a simplified version. In production, you might want to use a more robust hydration method
        $payment = new Payment();
        // Don't set ID as it's auto-generated
        $payment->setReferenceId($data['reference_id']);
        $payment->setAmount($data['amount']);
        $payment->setStatus($data['status']);
        $payment->setCreatedAt(new \DateTime($data['created_at']));

        return $payment;
    }
}
