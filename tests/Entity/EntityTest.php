<?php

namespace App\Tests\Entity;

use App\Entity\Payment;
use App\Entity\User;
use App\Tests\TestCase;

class EntityTest extends TestCase
{
    public function testPaymentEntity(): void
    {
        $payment = new Payment();
        $referenceId = 'entity_test_' . uniqid();
        $amount = 500;
        $status = 'CREATED';
        $createdAt = new \DateTime('2026-04-18 10:00:00');

        $payment->setReferenceId($referenceId);
        $payment->setAmount($amount);
        $payment->setStatus($status);
        $payment->setCreatedAt($createdAt);

        $this->assertEquals($referenceId, $payment->getReferenceId());
        $this->assertEquals($amount, $payment->getAmount());
        $this->assertEquals($status, $payment->getStatus());
        $this->assertEquals($createdAt, $payment->getCreatedAt());

        // Test default values
        $newPayment = new Payment();
        $this->assertEquals('PENDING', $newPayment->getStatus());
        $this->assertInstanceOf(\DateTime::class, $newPayment->getCreatedAt());
    }

    public function testUserEntity(): void
    {
        $user = new User();
        $email = 'test@example.com';
        $name = 'Test User';
        $createdAt = new \DateTime('2026-04-18 10:00:00');

        $user->setEmail($email);
        $user->setName($name);
        $user->setCreatedAt($createdAt);

        $this->assertEquals($email, $user->getEmail());
        $this->assertEquals($name, $user->getName());
        $this->assertEquals($createdAt, $user->getCreatedAt());

        // Test default values
        $newUser = new User();
        $this->assertInstanceOf(\DateTime::class, $newUser->getCreatedAt());
    }

    public function testPaymentPersistence(): void
    {
        $referenceId = 'persistence_test_' . uniqid();
        $amount = 300;

        $payment = new Payment();
        $payment->setReferenceId($referenceId);
        $payment->setAmount($amount);
        $payment->setStatus('CREATED');
        $payment->setCreatedAt(new \DateTime());

        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        $this->assertNotNull($payment->getId());

        // Clear entity manager and reload
        $this->entityManager->clear();

        $loadedPayment = $this->entityManager->getRepository(Payment::class)
            ->find($payment->getId());

        $this->assertNotNull($loadedPayment);
        $this->assertEquals($referenceId, $loadedPayment->getReferenceId());
        $this->assertEquals($amount, $loadedPayment->getAmount());
        $this->assertEquals('CREATED', $loadedPayment->getStatus());
    }

    public function testUserPersistence(): void
    {
        $email = 'persistence_' . uniqid() . '@example.com';
        $name = 'Persistence Test User';

        $user = new User();
        $user->setEmail($email);
        $user->setName($name);
        $user->setCreatedAt(new \DateTime());

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->assertNotNull($user->getId());

        // Clear entity manager and reload
        $this->entityManager->clear();

        $loadedUser = $this->entityManager->getRepository(User::class)
            ->find($user->getId());

        $this->assertNotNull($loadedUser);
        $this->assertEquals($email, $loadedUser->getEmail());
        $this->assertEquals($name, $loadedUser->getName());
    }
}