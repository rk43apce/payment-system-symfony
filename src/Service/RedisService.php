<?php

namespace App\Service;

use Predis\Client;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Psr\Log\LoggerInterface;

class RedisService
{
    private ?Client $redis;
    private CacheInterface $cache;
    private LoggerInterface $logger;

    public function __construct(
        CacheInterface $cache,
        LoggerInterface $logger,
        ?Client $redis = null
    ) {
        $this->cache = $cache;
        $this->logger = $logger;
        $this->redis = $redis;
    }

    /**
     * Factory method to create RedisService with optional Redis connection
     */
    public static function create(CacheInterface $cache, LoggerInterface $logger): self
    {
        $redis = null;

        try {
            if (class_exists('Predis\Client')) {
                $redis = new Client([
                    'host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
                    'port' => (int)($_ENV['REDIS_PORT'] ?? 6379),
                ]);

                // Test the connection
                $redis->ping();

                $logger->info('Redis connection established successfully');
            } else {
                $logger->warning('Predis\Client class not available, running without Redis');
            }
        } catch (\Throwable $e) {
            $logger->warning('Failed to connect to Redis, running without Redis', [
                'error' => $e->getMessage()
            ]);
            $redis = null;
        }

        return new self($cache, $logger, $redis);
    }

    /**
     * Sanitize cache key to remove reserved characters
     */
    private function sanitizeCacheKey(string $key): string
    {
        // Replace reserved characters with safe alternatives
        return str_replace(['{', '}', '(', ')', '/', '\\', '@', ':'], '_', $key);
    }

    /**
     * Cache payment data with TTL
     */
    public function cachePayment(string $referenceId, array $paymentData, int $ttl = 300): void
    {
        try {
            $cacheKey = $this->sanitizeCacheKey("payment:{$referenceId}");
            $this->cache->get($cacheKey, function (ItemInterface $item) use ($paymentData, $ttl) {
                $item->expiresAfter($ttl);
                return $paymentData;
            });

            $this->logger->debug('Payment cached in Redis', [
                'reference_id' => $referenceId,
                'cache_key' => $cacheKey,
                'ttl' => $ttl
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to cache payment', [
                'reference_id' => $referenceId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get cached payment data
     */
    public function getCachedPayment(string $referenceId): ?array
    {
        try {
            $cacheKey = $this->sanitizeCacheKey("payment:{$referenceId}");
            $data = $this->cache->get($cacheKey, function () {
                return null;
            });

            if ($data) {
                $this->logger->debug('Payment retrieved from cache', [
                    'reference_id' => $referenceId,
                    'cache_key' => $cacheKey
                ]);
            }

            return $data;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get cached payment', [
                'reference_id' => $referenceId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Invalidate payment cache
     */
    public function invalidatePaymentCache(string $referenceId): void
    {
        try {
            $cacheKey = $this->sanitizeCacheKey("payment:{$referenceId}");
            $this->cache->delete($cacheKey);

            $this->logger->debug('Payment cache invalidated', [
                'reference_id' => $referenceId,
                'cache_key' => $cacheKey
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to invalidate payment cache', [
                'reference_id' => $referenceId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Check rate limit for IP/client
     */
    public function checkRateLimit(string $identifier, int $maxRequests = 100, int $windowSeconds = 60): bool
    {
        // If Redis is not available, allow all requests (fail open)
        if (!$this->redis) {
            $this->logger->debug('Redis not available, allowing request', ['identifier' => $identifier]);
            return true;
        }

        try {
            $key = "ratelimit:{$identifier}";
            $current = $this->redis->get($key);

            if ($current === false) {
                // First request in window
                $this->redis->setex($key, $windowSeconds, 1);
                return true;
            }

            if ((int)$current >= $maxRequests) {
                $this->logger->warning('Rate limit exceeded', [
                    'identifier' => $identifier,
                    'current_requests' => $current,
                    'max_requests' => $maxRequests
                ]);
                return false;
            }

            $this->redis->incr($key);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Rate limit check failed', [
                'identifier' => $identifier,
                'error' => $e->getMessage()
            ]);
            // Allow request on Redis failure to avoid blocking legitimate traffic
            return true;
        }
    }

    /**
     * Acquire distributed lock
     */
    public function acquireLock(string $key, int $ttl = 30): bool
    {
        // If Redis is not available, allow all operations (no locking)
        if (!$this->redis) {
            $this->logger->debug('Redis not available, skipping lock acquisition', ['key' => $key]);
            return true;
        }

        try {
            $lockKey = "lock:{$key}";
            $result = $this->redis->set($lockKey, '1', ['NX', 'EX' => $ttl]);

            if ($result) {
                $this->logger->debug('Lock acquired', ['key' => $key, 'ttl' => $ttl]);
            }

            return (bool)$result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to acquire lock', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Release distributed lock
     */
    public function releaseLock(string $key): void
    {
        // If Redis is not available, nothing to release
        if (!$this->redis) {
            return;
        }

        try {
            $lockKey = "lock:{$key}";
            $this->redis->del($lockKey);

            $this->logger->debug('Lock released', ['key' => $key]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to release lock', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Cache idempotency check result
     */
    public function cacheIdempotencyCheck(string $referenceId, bool $exists, int $ttl = 300): void
    {
        try {
            $cacheKey = $this->sanitizeCacheKey("idempotency:{$referenceId}");
            $this->cache->get($cacheKey, function (ItemInterface $item) use ($exists, $ttl) {
                $item->expiresAfter($ttl);
                return $exists;
            });

            $this->logger->debug('Idempotency check cached', [
                'reference_id' => $referenceId,
                'exists' => $exists,
                'cache_key' => $cacheKey
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to cache idempotency check', [
                'reference_id' => $referenceId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get cached idempotency check result
     */
    public function getCachedIdempotencyCheck(string $referenceId): ?bool
    {
        try {
            $cacheKey = $this->sanitizeCacheKey("idempotency:{$referenceId}");
            return $this->cache->get($cacheKey, function () {
                return null;
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get cached idempotency check', [
                'reference_id' => $referenceId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}