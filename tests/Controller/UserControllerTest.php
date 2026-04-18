<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Service\UserService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;

final class UserControllerTest extends WebTestCase
{
    private UserService $userServiceMock;
    private LoggerInterface $loggerMock;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userServiceMock = $this->createMock(UserService::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->client = static::createClient();
        self::getContainer()->set(UserService::class, $this->userServiceMock);
        self::getContainer()->set(LoggerInterface::class, $this->loggerMock);
    }

    public function testCreateUserSuccess(): void
    {
        $user = new User();
        $user->setName('Alice');
        $user->setEmail('alice@example.com');

        $this->userServiceMock->expects($this->once())
            ->method('createUser')
            ->with('Alice', 'alice@example.com')
            ->willReturn($user);

        $this->client->request('POST', '/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_Idempotency-Key' => 'user-create-1'
        ], json_encode([
            'name' => 'Alice',
            'email' => 'alice@example.com'
        ]));

        self::assertResponseStatusCodeSame(201);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
        self::assertEquals('Alice', $data['data']['name']);
        self::assertEquals('alice@example.com', $data['data']['email']);
    }

    public function testGetUserSuccess(): void
    {
        $user = new User();
        $user->setName('Alice');
        $user->setEmail('alice@example.com');

        $this->userServiceMock->expects($this->once())
            ->method('getUser')
            ->with(1)
            ->willReturn($user);

        $this->client->request('GET', '/users/1');

        self::assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
        self::assertEquals('Alice', $data['data']['name']);
        self::assertEquals('alice@example.com', $data['data']['email']);
    }
}
