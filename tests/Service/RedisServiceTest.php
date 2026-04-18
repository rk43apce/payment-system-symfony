<?php

namespace App\Tests\Service;

use App\Service\RedisService;
use App\Tests\TestCase;
use Predis\Client;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;

class RedisServiceTest extends TestCase
{
    private RedisService $redisService;
    private $cacheMock;
    private $loggerMock;
    private $predisMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheMock = $this->createMock(CacheInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->predisMock = $this->createMock(Client::class);

        $this->redisService = new RedisService(
            $this->cacheMock,
            $this->loggerMock,
            $this->predisMock
        );
    }

    public function testCachePaymentSuccess(): void
    {
        $referenceId = 'test_ref_123';
        $paymentData = ['amount' => 100, 'status' => 'CREATED'];
        $ttl = 300;

        $this->cacheMock->expects($this->once())
            ->method('get')
            ->with('payment_test_ref_123')
            ->willReturnCallback(function ($key, $callback) use ($paymentData, $ttl) {
                $item = $this->createMock(\Symfony\Contracts\Cache\ItemInterface::class);
                $item->expects($this->once())->method('expiresAfter')->with($ttl);
                return $callback($item);
            });

        $this->loggerMock->expects($this->once())
            ->method('debug')
            ->with('Payment cached in Redis', [
                'reference_id' => $referenceId,
                'cache_key' => 'payment_test_ref_123',
                'ttl' => $ttl
            ]);

        $this->redisService->cachePayment($referenceId, $paymentData, $ttl);
    }

    public function testGetCachedPaymentHit(): void
    {
        $referenceId = 'test_ref_123';
        $expectedData = ['amount' => 100, 'status' => 'CREATED'];

        $this->cacheMock->expects($this->once())
            ->method('get')
            ->with('payment_test_ref_123')
            ->willReturn($expectedData);

        $this->loggerMock->expects($this->once())
            ->method('debug')
            ->with('Payment retrieved from cache', [
                'reference_id' => $referenceId,
                'cache_key' => 'payment_test_ref_123'
            ]);

        $result = $this->redisService->getCachedPayment($referenceId);

        $this->assertEquals($expectedData, $result);
    }

    public function testGetCachedPaymentMiss(): void
    {
        $referenceId = 'test_ref_123';

        $this->cacheMock->expects($this->once())
            ->method('get')
            ->with('payment_test_ref_123')
            ->willReturn(null);

        $this->loggerMock->expects($this->never())
            ->method('debug');

        $result = $this->redisService->getCachedPayment($referenceId);

        $this->assertNull($result);
    }

    public function testCachePaymentFailure(): void
    {
        $referenceId = 'test_ref_123';
        $paymentData = ['amount' => 100, 'status' => 'CREATED'];

        $this->cacheMock->expects($this->once())
            ->method('get')
            ->willThrowException(new \Exception('Cache error'));

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with('Failed to cache payment', [
                'reference_id' => $referenceId,
                'error' => 'Cache error'
            ]);

        $this->redisService->cachePayment($referenceId, $paymentData);
    }

    public function testCheckRateLimitWithinLimit(): void
    {
        $identifier = 'user_123';
        $endpoint = 'create_payment';
        $limit = 10;
        $window = 60;

        $this->predisMock->expects($this->once())
            ->method('__call')
            ->with('get', ['rate_limit:create_payment:user_123'])
            ->willReturn('5');

        $this->predisMock->expects($this->once())
            ->method('__call')
            ->with('incr', ['rate_limit:create_payment:user_123'])
            ->willReturn(6);

        $this->predisMock->expects($this->once())
            ->method('__call')
            ->with('expire', ['rate_limit:create_payment:user_123', $window])
            ->willReturn(1);

        $result = $this->redisService->checkRateLimit($identifier, $endpoint, $limit, $window);

        $this->assertTrue($result);
    }

    public function testCheckRateLimitExceeded(): void
    {
        $identifier = 'user_123';
        $endpoint = 'create_payment';
        $limit = 10;
        $window = 60;

        $this->predisMock->expects($this->once())
            ->method('__call')
            ->with('get', ['rate_limit:create_payment:user_123'])
            ->willReturn('10');

        $result = $this->redisService->checkRateLimit($identifier, $endpoint, $limit, $window);

        $this->assertFalse($result);
    }

    public function testAcquireLockSuccess(): void
    {
        $lockKey = 'payment_lock_123';
        $ttl = 30;

        $this->predisMock->expects($this->once())
            ->method('__call')
            ->with('set', [$lockKey, '1', 'NX', 'EX', $ttl])
            ->willReturn('OK');

        $result = $this->redisService->acquireLock($lockKey, $ttl);

        $this->assertTrue($result);
    }

    public function testAcquireLockFailure(): void
    {
        $lockKey = 'payment_lock_123';
        $ttl = 30;

        $this->predisMock->expects($this->once())
            ->method('__call')
            ->with('set', [$lockKey, '1', 'NX', 'EX', $ttl])
            ->willReturn(null);

        $result = $this->redisService->acquireLock($lockKey, $ttl);

        $this->assertFalse($result);
    }

    public function testReleaseLock(): void
    {
        $lockKey = 'payment_lock_123';

        $this->predisMock->expects($this->once())
            ->method('__call')
            ->with('del', [$lockKey])
            ->willReturn(1);

        $this->redisService->releaseLock($lockKey);
    }

    public function testSanitizeCacheKey(): void
    {
        $reflection = new \ReflectionClass($this->redisService);
        $method = $reflection->getMethod('sanitizeCacheKey');
        $method->setAccessible(true);

        $result = $method->invoke($this->redisService, 'payment:{test}/ref@123');

        $this->assertEquals('payment__test__ref_123', $result);
    }
}