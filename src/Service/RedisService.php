<?php

namespace App\Service;

use Predis\Client;
use Psr\Log\LoggerInterface;

class RedisService
{
    private ?Client $redis = null;

    public function __construct(private LoggerInterface $logger)
    {
        try {
            $client = new Client([
                'host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
                'port' => (int) ($_ENV['REDIS_PORT'] ?? 6379),
            ]);
            $client->ping();
            $this->redis = $client;
            $this->logger->info('Redis connection established');
        } catch (\Throwable $e) {
            $this->logger->warning('Redis unavailable, running without distributed locking', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Acquire a distributed lock using SET NX EX (atomic).
     * Returns true if lock acquired, false if already held.
     * When Redis is down returns true (fail-open) — DB unique constraint acts as hard guard.
     */
    public function acquireLock(string $key, int $ttl = 30): bool
    {
        if (!$this->redis) {
            $this->logger->warning('Redis down — lock skipped, relying on DB constraint', ['key' => $key]);
            return true;
        }

        try {
            $result = $this->redis->set("lock:{$key}", '1', ['NX', 'EX' => $ttl]);
            return (bool) $result;
        } catch (\Throwable $e) {
            $this->logger->error('Lock acquisition failed', ['key' => $key, 'error' => $e->getMessage()]);
            return true; // fail-open, DB is the safety net
        }
    }

    /**
     * Release a distributed lock.
     */
    public function releaseLock(string $key): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $this->redis->del("lock:{$key}");
        } catch (\Throwable $e) {
            $this->logger->warning('Lock release failed', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Sliding window rate limiter.
     * Returns true if request is allowed, false if limit exceeded.
     */
    public function checkRateLimit(string $identifier, int $maxRequests = 100, int $windowSeconds = 60): bool
    {
        if (!$this->redis) {
            return true; // fail-open
        }

        try {
            $key     = "ratelimit:{$identifier}";
            $current = $this->redis->get($key);

            if ($current === null) {
                $this->redis->setex($key, $windowSeconds, 1);
                return true;
            }

            if ((int) $current >= $maxRequests) {
                $this->logger->warning('Rate limit exceeded', [
                    'identifier'       => $identifier,
                    'current_requests' => $current,
                    'max_requests'     => $maxRequests,
                ]);
                return false;
            }

            $this->redis->incr($key);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Rate limit check failed', ['identifier' => $identifier, 'error' => $e->getMessage()]);
            return true;
        }
    }
}
