<?php

namespace App\Tests\Controller;

use App\Entity\AccountLedger;
use App\Entity\User;
use App\Enum\LedgerReferenceType;
use App\Enum\LedgerType;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TransferControllerTest extends WebTestCase
{
    private function post(string $uri, array $body, string $key = 'test-key-12345'): array
    {
        $client = static::createClient();
        $client->request('POST', $uri,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_Idempotency-Key' => $key],
            json_encode($body)
        );
        return [
            'status' => $client->getResponse()->getStatusCode(),
            'body'   => json_decode($client->getResponse()->getContent(), true),
        ];
    }

    private function get(string $uri): array
    {
        $client = static::createClient();
        $client->request('GET', $uri);
        return [
            'status' => $client->getResponse()->getStatusCode(),
            'body'   => json_decode($client->getResponse()->getContent(), true),
        ];
    }

    private function createUserWithBalance(int $balance): User
    {
        $em   = static::getContainer()->get('doctrine')->getManager();
        $user = new User();
        $user->setName('Test')->setEmail(uniqid() . '@test.com');
        $em->persist($user);
        $em->flush();

        if ($balance > 0) {
            $entry = new AccountLedger();
            $entry->setUser($user)
                ->setAmount($balance)
                ->setType(LedgerType::CREDIT)
                ->setReferenceType(LedgerReferenceType::TOP_UP)
                ->setCreatedAt(new \DateTimeImmutable());
            $em->persist($entry);
            $em->flush();
        }

        return $user;
    }

    // ── Balance endpoints ────────────────────────────────────────────────────

    public function testGetBalanceReturnsZero(): void
    {
        $user   = $this->createUserWithBalance(0);
        $result = $this->get('/balance/' . $user->getId());

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['body']['success']);
        $this->assertSame(0.0, $result['body']['data']['balance']);
    }

    public function testGetBalanceUserNotFound(): void
    {
        $result = $this->get('/balance/99999');
        $this->assertSame(404, $result['status']);
        $this->assertSame('not_found', $result['body']['error']['code']);
    }

    public function testAddBalanceSuccess(): void
    {
        $user   = $this->createUserWithBalance(0);
        $result = $this->post('/balance/add', ['user_id' => $user->getId(), 'amount' => 100.50], 'topup-ctrl-001xx');

        $this->assertSame(200, $result['status']);
        $this->assertSame(100.50, $result['body']['data']['balance']);
    }

    public function testAddBalanceMissingIdempotencyKey(): void
    {
        $client = static::createClient();
        $client->request('POST', '/balance/add',
            [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['user_id' => 1, 'amount' => 10])
        );
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('missing_idempotency_key', $body['error']['code']);
    }

    public function testAddBalanceMissingUserId(): void
    {
        $result = $this->post('/balance/add', ['amount' => 10.0], 'topup-ctrl-002xx');
        $this->assertSame(422, $result['status']);
        $this->assertSame('validation_error', $result['body']['error']['code']);
    }

    // ── Transfer endpoint ────────────────────────────────────────────────────

    public function testTransferSuccess(): void
    {
        $sender    = $this->createUserWithBalance(10000);
        $recipient = $this->createUserWithBalance(0);

        $result = $this->post('/transfer', [
            'sender_id'    => $sender->getId(),
            'recipient_id' => $recipient->getId(),
            'amount'       => 50.00,
        ], 'txn-ctrl-001xxxxx');

        $this->assertSame(201, $result['status']);
        $this->assertTrue($result['body']['success']);
        $this->assertSame(50.0, $result['body']['data']['amount']);
        $this->assertSame('COMPLETED', $result['body']['data']['status']);
    }

    public function testTransferInsufficientFunds(): void
    {
        $sender    = $this->createUserWithBalance(100);
        $recipient = $this->createUserWithBalance(0);

        $result = $this->post('/transfer', [
            'sender_id'    => $sender->getId(),
            'recipient_id' => $recipient->getId(),
            'amount'       => 50.00,
        ], 'txn-ctrl-002xxxxx');

        $this->assertSame(409, $result['status']);
        $this->assertSame('transfer_error', $result['body']['error']['code']);
    }

    public function testTransferValidationError(): void
    {
        $result = $this->post('/transfer', ['sender_id' => 1], 'txn-ctrl-003xxxxx');

        $this->assertSame(422, $result['status']);
        $this->assertSame('validation_error', $result['body']['error']['code']);
        $this->assertArrayHasKey('recipient_id', $result['body']['error']['details']);
        $this->assertArrayHasKey('amount', $result['body']['error']['details']);
    }

    public function testTransferMissingIdempotencyKey(): void
    {
        $client = static::createClient();
        $client->request('POST', '/transfer',
            [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['sender_id' => 1, 'recipient_id' => 2, 'amount' => 10])
        );
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('missing_idempotency_key', $body['error']['code']);
    }

    public function testTransferIsIdempotent(): void
    {
        $sender    = $this->createUserWithBalance(20000);
        $recipient = $this->createUserWithBalance(0);
        $key       = 'txn-ctrl-idem-001xx';

        $first  = $this->post('/transfer', [
            'sender_id' => $sender->getId(), 'recipient_id' => $recipient->getId(), 'amount' => 10.0,
        ], $key);

        $second = $this->post('/transfer', [
            'sender_id' => $sender->getId(), 'recipient_id' => $recipient->getId(), 'amount' => 10.0,
        ], $key);

        $this->assertSame(201, $first['status']);
        $this->assertSame(201, $second['status']);
        $this->assertSame($first['body']['data']['id'], $second['body']['data']['id']);
    }
}
