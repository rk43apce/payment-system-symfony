<?php

namespace App\Tests\Controller;

use App\Entity\Payment;
use App\Service\PaymentService;
use App\Service\RateLimitService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class PaymentControllerTest extends WebTestCase
{
    private $paymentServiceMock;
    private $rateLimitServiceMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paymentServiceMock = $this->createMock(PaymentService::class);
        $this->rateLimitServiceMock = $this->createMock(RateLimitService::class);

        // Replace services in container
        self::getContainer()->set(PaymentService::class, $this->paymentServiceMock);
        self::getContainer()->set(RateLimitService::class, $this->rateLimitServiceMock);
    }

    public function testCreatePaymentSuccess(): void
    {
        $referenceId = 'test_ref_123';
        $amount = 500;

        $payment = new Payment();
        $payment->setReferenceId($referenceId);
        $payment->setAmount($amount);
        $payment->setStatus('CREATED');
        $payment->setCreatedAt(new \DateTime());

        $this->paymentServiceMock->expects($this->once())
            ->method('createPayment')
            ->with($referenceId, $amount)
            ->willReturn($payment);

        $this->rateLimitServiceMock->expects($this->once())
            ->method('checkRateLimit')
            ->willReturn(null);

        $client = static::createClient();
        $client->request('POST', '/payment', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'reference_id' => $referenceId,
            'amount' => $amount
        ]));

        self::assertResponseIsSuccessful();

        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);

        self::assertTrue($data['success']);
        self::assertEquals($referenceId, $data['data']['reference_id']);
        self::assertEquals($amount, $data['data']['amount']);
        self::assertEquals('CREATED', $data['data']['status']);
    }

    public function testCreatePaymentRateLimited(): void
    {
        $rateLimitResponse = new Response('Rate limited', 429);

        $this->rateLimitServiceMock->expects($this->once())
            ->method('checkRateLimit')
            ->willReturn($rateLimitResponse);

        $client = static::createClient();
        $client->request('POST', '/payment', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'reference_id' => 'test_ref_123',
            'amount' => 500
        ]));

        self::assertResponseStatusCodeSame(429);
    }

    public function testCreatePaymentInvalidJson(): void
    {
        $this->rateLimitServiceMock->expects($this->once())
            ->method('checkRateLimit')
            ->willReturn(null);

        $client = static::createClient();
        $client->request('POST', '/payment', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], 'invalid json');

        self::assertResponseStatusCodeSame(400);

        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);

        self::assertFalse($data['success']);
        self::assertStringContains('Invalid JSON', $data['error']['message']);
    }

    public function testCreatePaymentMissingFields(): void
    {
        $this->rateLimitServiceMock->expects($this->once())
            ->method('checkRateLimit')
            ->willReturn(null);

        $client = static::createClient();
        $client->request('POST', '/payment', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'amount' => 500
            // missing reference_id
        ]));

        self::assertResponseStatusCodeSame(400);

        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);

        self::assertFalse($data['success']);
        self::assertStringContains('reference_id', $data['error']['message']);
    }

    public function testGetPaymentSuccess(): void
    {
        $referenceId = 'test_ref_456';

        $payment = new Payment();
        $payment->setReferenceId($referenceId);
        $payment->setAmount(300);
        $payment->setStatus('CREATED');
        $payment->setCreatedAt(new \DateTime());

        $this->paymentServiceMock->expects($this->once())
            ->method('getPayment')
            ->with($referenceId)
            ->willReturn($payment);

        $this->rateLimitServiceMock->expects($this->once())
            ->method('checkRateLimit')
            ->willReturn(null);

        $client = static::createClient();
        $client->request('GET', '/payment/' . $referenceId);

        self::assertResponseIsSuccessful();

        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);

        self::assertTrue($data['success']);
        self::assertEquals($referenceId, $data['data']['reference_id']);
        self::assertEquals(300, $data['data']['amount']);
    }

    public function testGetPaymentNotFound(): void
    {
        $referenceId = 'nonexistent_ref';

        $this->paymentServiceMock->expects($this->once())
            ->method('getPayment')
            ->with($referenceId)
            ->willThrowException(new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Payment not found'));

        $this->rateLimitServiceMock->expects($this->once())
            ->method('checkRateLimit')
            ->willReturn(null);

        $client = static::createClient();
        $client->request('GET', '/payment/' . $referenceId);

        self::assertResponseStatusCodeSame(404);

        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);

        self::assertFalse($data['success']);
        self::assertEquals('Payment not found', $data['error']['message']);
    }

    public function testProcessPaymentSuccess(): void
    {
        $referenceId = 'process_ref_789';

        $payment = new Payment();
        $payment->setReferenceId($referenceId);
        $payment->setAmount(400);
        $payment->setStatus('PROCESSED');
        $payment->setCreatedAt(new \DateTime());

        $this->paymentServiceMock->expects($this->once())
            ->method('processPayment')
            ->with($referenceId)
            ->willReturn($payment);

        $this->rateLimitServiceMock->expects($this->once())
            ->method('checkRateLimit')
            ->willReturn(null);

        $client = static::createClient();
        $client->request('POST', '/payment/' . $referenceId . '/process');

        self::assertResponseIsSuccessful();

        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);

        self::assertTrue($data['success']);
        self::assertEquals('PROCESSED', $data['data']['status']);
    }

    public function testIndex(): void
    {
        $client = static::createClient();
        $client->request('GET', '/payment');

        self::assertResponseIsSuccessful();
    }
}
