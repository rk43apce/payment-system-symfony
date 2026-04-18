<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Response;
use App\Service\RateLimitService;
use Psr\Log\LoggerInterface;

class RateLimitSubscriber implements EventSubscriberInterface
{
    private RateLimitService $rateLimitService;
    private LoggerInterface $logger;

    public function __construct(RateLimitService $rateLimitService, LoggerInterface $logger)
    {
        $this->rateLimitService = $rateLimitService;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 100], // High priority to run early
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        // Only apply rate limiting to payment API routes
        if (!$this->isPaymentApiRoute($request->getPathInfo())) {
            return;
        }

        // Determine endpoint type from route
        $endpoint = $this->getEndpointType($request);

        // Check rate limit
        $rateLimitResponse = $this->rateLimitService->checkRateLimit($request, $endpoint);

        if ($rateLimitResponse !== null) {
            $event->setResponse($rateLimitResponse);
            return;
        }

        $this->logger->debug('Rate limit check passed', [
            'endpoint' => $endpoint,
            'path' => $request->getPathInfo(),
            'method' => $request->getMethod(),
        ]);
    }

    private function isPaymentApiRoute(string $path): bool
    {
        return str_starts_with($path, '/transfer') || str_starts_with($path, '/balance');
    }

    private function getEndpointType(\Symfony\Component\HttpFoundation\Request $request): string
    {
        $path   = $request->getPathInfo();
        $method = $request->getMethod();

        if ($path === '/transfer' && $method === 'POST') {
            return 'transfer';
        }

        if ($path === '/balance/add' && $method === 'POST') {
            return 'balance_add';
        }

        if (preg_match('#^/balance/\d+$#', $path) && $method === 'GET') {
            return 'balance_get';
        }

        return 'api';
    }
}