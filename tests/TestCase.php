<?php

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

abstract class TestCase extends KernelTestCase
{
    protected ?EntityManagerInterface $entityManager;
    protected ContainerInterface $container;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->container = $kernel->getContainer();

        $this->entityManager = $this->container->get('doctrine')->getManager();

        // Begin transaction for each test
        $this->entityManager->getConnection()->beginTransaction();
        $this->entityManager->getConnection()->setAutoCommit(false);
    }

    protected function tearDown(): void
    {
        // Rollback transaction to clean up test data
        if ($this->entityManager && $this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }

        $this->entityManager = null;

        parent::tearDown();
    }

    protected function createPayment(array $data = []): \App\Entity\Payment
    {
        $payment = new \App\Entity\Payment();
        $payment->setReferenceId($data['reference_id'] ?? 'test_ref_' . uniqid());
        $payment->setAmount($data['amount'] ?? 100);
        $payment->setStatus($data['status'] ?? 'CREATED');
        $payment->setCreatedAt($data['created_at'] ?? new \DateTime());

        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        return $payment;
    }

    protected function createUser(array $data = []): \App\Entity\User
    {
        $user = new \App\Entity\User();
        $user->setEmail($data['email'] ?? 'test@example.com');
        $user->setName($data['name'] ?? 'Test User');
        $user->setCreatedAt($data['created_at'] ?? new \DateTime());

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}