<?php

namespace App\Tests\Integration;

use App\Entity\Payment;
use App\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

class PaymentControllerIntegrationTest extends TestCase
{
    public function testCreatePaymentEndpoint(): void
    {
        $client = static::createClient();
        $referenceId = 'api_test_' . uniqid();

        $client->request('POST', '/payment', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'reference_id' => $referenceId,
            'amount' => 200
        ]));

        $this->assertResponseIsSuccessful();

        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertEquals($referenceId, $data['data']['reference_id']);
        $this->assertEquals(200, $data['data']['amount']);
        $this->assertEquals('CREATED', $data['data']['status']);

        // Verify in database
        $payment = $this->entityManager->getRepository(Payment::class)
            ->findOneBy(['referenceId' => $referenceId]);
        $this->assertNotNull($payment);
    }

    public function testGetPaymentEndpoint(): void
    {
        $client = static::createClient();
        $referenceId = 'get_api_test_' . uniqid();

        // Create payment first
        $payment = $this->createPayment([
            'reference_id' => $referenceId,
            'amount' => 150
        ]);

        // Get payment via API
        $client->request('GET', '/payment/' . $referenceId);

        $this->assertResponseIsSuccessful();

        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertEquals($referenceId, $data['data']['reference_id']);
        $this->assertEquals(150, $data['data']['amount']);
        $this->assertEquals('CREATED', $data['data']['status']);
    }

    public function testGetPaymentNotFound(): void
    {
        $client = static::createClient();
        $nonExistentRef = 'nonexistent_' . uniqid();

        $client->request('GET', '/payment/' . $nonExistentRef);

        $this->assertResponseStatusCodeSame(404);

        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);

        $this->assertFalse($data['success']);
        $this->assertEquals('Payment not found', $data['error']['message']);
    }

    public function testProcessPaymentEndpoint(): void
    {
        $client = static::createClient();
        $referenceId = 'process_api_test_' . uniqid();

        // Create payment first
        $this->createPayment([
            'reference_id' => $referenceId,
            'amount' => 300
        ]);

        // Process payment via API
        $client->request('POST', '/payment/' . $referenceId . '/process');

        $this->assertResponseIsSuccessful();

        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertEquals('PROCESSED', $data['data']['status']);

        // Verify in database
        $payment = $this->entityManager->getRepository(Payment::class)
            ->findOneBy(['referenceId' => $referenceId]);
        $this->assertEquals('PROCESSED', $payment->getStatus());
    }

    public function testRateLimiting(): void
    {
        $client = static::createClient();

        // Make multiple requests to trigger rate limiting
        for ($i = 0; $i < 15; $i++) {
            $referenceId = 'rate_limit_test_' . $i . '_' . uniqid();
            $client->request('POST', '/payment', [], [], [
                'CONTENT_TYPE' => 'application/json'
            ], json_encode([
                'reference_id' => $referenceId,
                'amount' => 100
            ]));
        }

        // The last request should be rate limited
        $this->assertResponseStatusCodeSame(429);

        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);

        $this->assertFalse($data['success']);
        $this->assertEquals('rate_limit_exceeded', $data['error']['code']);
    }

    public function testInvalidJsonRequest(): void
    {
        $client = static::createClient();

        $client->request('POST', '/payment', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], 'invalid json');

        $this->assertResponseStatusCodeSame(400);

        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);

        $this->assertFalse($data['success']);
        $this->assertStringContains('Invalid JSON', $data['error']['message']);
    }

    public function testMissingRequiredFields(): void
    {
        $client = static::createClient();

        $client->request('POST', '/payment', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'amount' => 100
            // missing reference_id
        ]));

        $this->assertResponseStatusCodeSame(400);

        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);

        $this->assertFalse($data['success']);
        $this->assertStringContains('reference_id', $data['error']['message']);
    }

    public function testIdempotentPaymentCreation(): void
    {
        $client = static::createClient();
        $referenceId = 'idempotent_api_test_' . uniqid();

        // Create payment first time
        $client->request('POST', '/payment', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'reference_id' => $referenceId,
            'amount' => 250
        ]));

        $this->assertResponseIsSuccessful();
        $response1 = $client->getResponse();
        $data1 = json_decode($response1->getContent(), true);

        // Create payment second time with same reference ID
        $client->request('POST', '/payment', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'reference_id' => $referenceId,
            'amount' => 250
        ]));

        $this->assertResponseIsSuccessful();
        $response2 = $client->getResponse();
        $data2 = json_decode($response2->getContent(), true);

        // Should return the same payment data
        $this->assertEquals($data1['data']['id'], $data2['data']['id']);
        $this->assertEquals($referenceId, $data2['data']['reference_id']);

        // Verify only one payment in database
        $payments = $this->entityManager->getRepository(Payment::class)
            ->findBy(['referenceId' => $referenceId]);
        $this->assertCount(1, $payments);
    }
}