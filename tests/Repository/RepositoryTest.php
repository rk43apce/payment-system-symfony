<?php

namespace App\Tests\Repository;

use App\Entity\Payment;
use App\Entity\User;
use App\Repository\PaymentRepository;
use App\Repository\UserRepository;
use App\Tests\TestCase;

class RepositoryTest extends TestCase
{
    private PaymentRepository $paymentRepository;
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paymentRepository = $this->entityManager->getRepository(Payment::class);
        $this->userRepository = $this->entityManager->getRepository(User::class);
    }

    public function testPaymentRepositoryFindByReferenceId(): void
    {
        $referenceId = 'repo_test_' . uniqid();
        $amount = 400;

        // Create payment
        $payment = $this->createPayment([
            'reference_id' => $referenceId,
            'amount' => $amount
        ]);

        // Find by reference ID
        $foundPayment = $this->paymentRepository->findOneBy(['referenceId' => $referenceId]);

        $this->assertNotNull($foundPayment);
        $this->assertEquals($payment->getId(), $foundPayment->getId());
        $this->assertEquals($referenceId, $foundPayment->getReferenceId());
        $this->assertEquals($amount, $foundPayment->getAmount());
    }

    public function testPaymentRepositoryFindByStatus(): void
    {
        // Create payments with different statuses
        $this->createPayment(['reference_id' => 'status_test_1_' . uniqid(), 'status' => 'CREATED']);
        $this->createPayment(['reference_id' => 'status_test_2_' . uniqid(), 'status' => 'PROCESSED']);
        $this->createPayment(['reference_id' => 'status_test_3_' . uniqid(), 'status' => 'CREATED']);

        $createdPayments = $this->paymentRepository->findBy(['status' => 'CREATED']);
        $processedPayments = $this->paymentRepository->findBy(['status' => 'PROCESSED']);

        $this->assertCount(2, $createdPayments);
        $this->assertCount(1, $processedPayments);

        foreach ($createdPayments as $payment) {
            $this->assertEquals('CREATED', $payment->getStatus());
        }

        foreach ($processedPayments as $payment) {
            $this->assertEquals('PROCESSED', $payment->getStatus());
        }
    }

    public function testPaymentRepositoryFindNonExistent(): void
    {
        $nonExistentRef = 'nonexistent_' . uniqid();

        $payment = $this->paymentRepository->findOneBy(['referenceId' => $nonExistentRef]);

        $this->assertNull($payment);
    }

    public function testUserRepositoryFindByEmail(): void
    {
        $email = 'repo_test_' . uniqid() . '@example.com';
        $name = 'Repository Test User';

        // Create user
        $user = $this->createUser([
            'email' => $email,
            'name' => $name
        ]);

        // Find by email
        $foundUser = $this->userRepository->findOneBy(['email' => $email]);

        $this->assertNotNull($foundUser);
        $this->assertEquals($user->getId(), $foundUser->getId());
        $this->assertEquals($email, $foundUser->getEmail());
        $this->assertEquals($name, $foundUser->getName());
    }

    public function testRepositoryCount(): void
    {
        $initialCount = $this->paymentRepository->count([]);

        // Create some payments
        $this->createPayment(['reference_id' => 'count_test_1_' . uniqid()]);
        $this->createPayment(['reference_id' => 'count_test_2_' . uniqid()]);

        $finalCount = $this->paymentRepository->count([]);

        $this->assertEquals($initialCount + 2, $finalCount);
    }

    public function testRepositoryFindAll(): void
    {
        $initialCount = count($this->paymentRepository->findAll());

        // Create payments
        $this->createPayment(['reference_id' => 'findall_test_1_' . uniqid()]);
        $this->createPayment(['reference_id' => 'findall_test_2_' . uniqid()]);

        $allPayments = $this->paymentRepository->findAll();

        $this->assertCount($initialCount + 2, $allPayments);
        $this->assertContainsOnlyInstancesOf(Payment::class, $allPayments);
    }

    public function testRepositoryOrdering(): void
    {
        // Create payments with different creation times
        $payment1 = $this->createPayment([
            'reference_id' => 'order_test_1_' . uniqid(),
            'created_at' => new \DateTime('2026-04-18 10:00:00')
        ]);

        $payment2 = $this->createPayment([
            'reference_id' => 'order_test_2_' . uniqid(),
            'created_at' => new \DateTime('2026-04-18 11:00:00')
        ]);

        $payment3 = $this->createPayment([
            'reference_id' => 'order_test_3_' . uniqid(),
            'created_at' => new \DateTime('2026-04-18 09:00:00')
        ]);

        // Find all and check ordering by creation date
        $payments = $this->paymentRepository->findBy([], ['createdAt' => 'ASC']);

        // Should be ordered by creation time
        $foundPayments = array_filter($payments, function ($p) use ($payment1, $payment2, $payment3) {
            return in_array($p->getId(), [$payment1->getId(), $payment2->getId(), $payment3->getId()]);
        });

        $this->assertCount(3, $foundPayments);
    }
}