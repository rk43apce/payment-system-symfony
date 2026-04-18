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
        // Apply rate limiting only to payment-related routes
        return str_starts_with($path, '/payment');
    }

    private function getEndpointType(\Symfony\Component\HttpFoundation\Request $request): string
    {
        $path = $request->getPathInfo();
        $method = $request->getMethod();

        // Determine endpoint type based on path and method
        if ($path === '/payment' && $method === 'POST') {
            return 'create_payment';
        }

        if ($path === '/payment/process' && $method === 'POST') {
            return 'process_payment';
        }

        if (preg_match('#^/payment/[^/]+$#', $path) && $method === 'GET') {
            return 'get_payment';
        }

        // Default fallback
        return 'payment_api';
    }
}