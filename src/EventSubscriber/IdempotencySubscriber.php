<?php

namespace App\EventSubscriber;

use App\Response\ErrorResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class IdempotencySubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 90], // runs after RateLimitSubscriber (100)
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
            $event->setResponse(ErrorResponse::create(
                'missing_idempotency_key',
                'Idempotency-Key header is required for POST requests.',
                'invalid_request_error',
                422
            ));
            return;
        }

        if (strlen($key) < 8 || strlen($key) > 64) {
            $event->setResponse(ErrorResponse::create(
                'invalid_idempotency_key',
                'Idempotency-Key must be between 8 and 64 characters.',
                'invalid_request_error',
                422
            ));
        }
    }
}
