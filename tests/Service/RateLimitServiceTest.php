<?php

namespace App\Tests\Service;

use App\Service\RateLimitService;
use App\Service\RedisService;
use App\Tests\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

class RateLimitServiceTest extends TestCase
{
    private RateLimitService $rateLimitService;
    private $redisServiceMock;
    private $loggerMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redisServiceMock = $this->createMock(RedisService::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->rateLimitService = new RateLimitService(
            $this->redisServiceMock,
            $this->loggerMock
        );
    }

    public function testCheckRateLimitAllowed(): void
    {
        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method('getClientIp')
            ->willReturn('127.0.0.1');

        $request->headers = $this->createMock(\Symfony\Component\HttpFoundation\HeaderBag::class);
        $request->headers->expects($this->once())
            ->method('get')
            ->with('User-Agent', 'unknown')
            ->willReturn('TestAgent/1.0');

        $this->redisServiceMock->expects($this->once())
            ->method('checkRateLimit')
            ->willReturn(true);

        $result = $this->rateLimitService->checkRateLimit($request, 'create_payment');

        $this->assertNull($result);
    }

    public function testCheckRateLimitExceeded(): void
    {
        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method('getClientIp')
            ->willReturn('127.0.0.1');

        $request->headers = $this->createMock(\Symfony\Component\HttpFoundation\HeaderBag::class);
        $request->headers->expects($this->once())
            ->method('get')
            ->with('User-Agent', 'unknown')
            ->willReturn('TestAgent/1.0');

        $this->redisServiceMock->expects($this->once())
            ->method('checkRateLimit')
            ->willReturn(false);

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with('Rate limit exceeded', $this->callback(function ($context) {
                return isset($context['endpoint']) && $context['endpoint'] === 'create_payment';
            }));

        $result = $this->rateLimitService->checkRateLimit($request, 'create_payment');

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\Response::class, $result);
        $this->assertEquals(429, $result->getStatusCode());

        $responseData = json_decode($result->getContent(), true);
        $this->assertEquals('rate_limit_exceeded', $responseData['error']['code']);
    }

    public function testGetEndpointLimits(): void
    {
        $reflection = new \ReflectionClass($this->rateLimitService);
        $method = $reflection->getMethod('getEndpointLimits');
        $method->setAccessible(true);

        $limits = $method->invoke($this->rateLimitService, 'create_payment');
        $this->assertEquals(['max_requests' => 10, 'window_seconds' => 60], $limits);

        $limits = $method->invoke($this->rateLimitService, 'process_payment');
        $this->assertEquals(['max_requests' => 5, 'window_seconds' => 60], $limits);

        $limits = $method->invoke($this->rateLimitService, 'get_payment');
        $this->assertEquals(['max_requests' => 30, 'window_seconds' => 60], $limits);

        $limits = $method->invoke($this->rateLimitService, 'unknown_endpoint');
        $this->assertEquals(['max_requests' => 20, 'window_seconds' => 60], $limits);
    }

    public function testCreateRateLimitIdentifier(): void
    {
        $reflection = new \ReflectionClass($this->rateLimitService);
        $method = $reflection->getMethod('createRateLimitIdentifier');
        $method->setAccessible(true);

        $identifier1 = $method->invoke($this->rateLimitService, '127.0.0.1', 'TestAgent', 'create_payment');
        $identifier2 = $method->invoke($this->rateLimitService, '127.0.0.1', 'TestAgent', 'create_payment');

        // Same inputs should produce same hash
        $this->assertEquals($identifier1, $identifier2);
        $this->assertEquals(64, strlen($identifier1)); // SHA256 produces 64 character hex string

        $identifier3 = $method->invoke($this->rateLimitService, '127.0.0.1', 'DifferentAgent', 'create_payment');
        $this->assertNotEquals($identifier1, $identifier3);
    }
}