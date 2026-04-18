<?php

namespace App\EventSubscriber;

use App\Response\ErrorResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Psr\Log\LoggerInterface;

class IdempotencySubscriber implements EventSubscriberInterface
{
    public function __construct(private LoggerInterface $logger) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 90],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if ($request->getMethod() !== 'POST') {
            return;
        }

        $key = trim($request->headers->get('Idempotency-Key', ''));

        if ($key === '') {
            $this->logger->warning('Missing Idempotency-Key header', [
                'path'   => $request->getPathInfo(),
                'method' => $request->getMethod(),
                'ip'     => $request->getClientIp(),
            ]);
            $event->setResponse(ErrorResponse::create(
                'missing_idempotency_key',
                'Idempotency-Key header is required for POST requests.',
                'invalid_request_error',
                422
            ));
            return;
        }

        if (strlen($key) < 8 || strlen($key) > 64) {
            $this->logger->warning('Invalid Idempotency-Key length', [
                'path'            => $request->getPathInfo(),
                'idempotency_key' => $key,
                'length'          => strlen($key),
            ]);
            $event->setResponse(ErrorResponse::create(
                'invalid_idempotency_key',
                'Idempotency-Key must be between 8 and 64 characters.',
                'invalid_request_error',
                422
            ));
        }
    }
}
