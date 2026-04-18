<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Response\ErrorResponse;
use Psr\Log\LoggerInterface;

class RateLimitService
{
    private RedisService $redis;
    private LoggerInterface $logger;

    public function __construct(RedisService $redis, LoggerInterface $logger)
    {
        $this->redis = $redis;
        $this->logger = $logger;
    }

    /**
     * Check if request should be rate limited
     */
    public function checkRateLimit(Request $request, string $endpoint): ?Response
    {
        $clientIp = $request->getClientIp();
        $userAgent = $request->headers->get('User-Agent', 'unknown');

        // Create a unique identifier for rate limiting
        $identifier = $this->createRateLimitIdentifier($clientIp, $userAgent, $endpoint);

        // Different limits for different endpoints
        $limits = $this->getEndpointLimits($endpoint);

        $allowed = $this->redis->checkRateLimit($identifier, $limits['max_requests'], $limits['window_seconds']);

        if (!$allowed) {
            $this->logger->warning('Rate limit exceeded', [
                'endpoint' => $endpoint,
                'client_ip' => $clientIp,
                'user_agent' => $userAgent,
                'identifier' => $identifier
            ]);

            return new Response(
                json_encode([
                    'error' => [
                        'code' => 'rate_limit_exceeded',
                        'message' => 'Too many requests. Please try again later.',
                        'type' => 'rate_limit_error'
                    ],
                    'request_id' => uniqid('req_', true)
                ]),
                429,
                ['Content-Type' => 'application/json']
            );
        }

        return null; // No rate limit violation
    }

    private function createRateLimitIdentifier(string $clientIp, string $userAgent, string $endpoint): string
    {
        // Create a hash of the combination to avoid storing sensitive data
        return hash('sha256', $clientIp . $userAgent . $endpoint);
    }

    private function getEndpointLimits(string $endpoint): array
    {
        // Define different rate limits for different endpoints
        $limits = [
            'create_payment' => ['max_requests' => 50, 'window_seconds' => 60],   // 50 requests per minute
            'process_payment' => ['max_requests' => 50, 'window_seconds' => 60],   // 50 requests per minute
            'get_payment' => ['max_requests' => 100, 'window_seconds' => 60],      // 100 requests per minute
        ];

        return $limits[$endpoint] ?? ['max_requests' => 20, 'window_seconds' => 60]; // Default
    }
}