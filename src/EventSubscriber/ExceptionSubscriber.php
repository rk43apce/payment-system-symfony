<?php

namespace App\EventSubscriber;

use App\Response\ErrorResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class ExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private LoggerInterface $logger) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', 0]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $e         = $event->getThrowable();
        $requestId = 'req_' . bin2hex(random_bytes(8));

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $this->logger->warning('HTTP exception', [
                'request_id' => $requestId,
                'status'     => $status,
                'message'    => $e->getMessage(),
                'path'       => $event->getRequest()->getPathInfo(),
            ]);
            $event->setResponse(ErrorResponse::create('http_error', $e->getMessage(), 'invalid_request_error', $status, [], $requestId));
            return;
        }

        $this->logger->error('Unhandled exception', [
            'request_id' => $requestId,
            'error'      => $e->getMessage(),
            'class'      => get_class($e),
            'file'       => $e->getFile(),
            'line'       => $e->getLine(),
            'trace'      => $e->getTraceAsString(),
            'path'       => $event->getRequest()->getPathInfo(),
            'method'     => $event->getRequest()->getMethod(),
        ]);

        $event->setResponse(ErrorResponse::create(
            'internal_server_error',
            'An unexpected error occurred. Please try again.',
            'api_error',
            500,
            [],
            $requestId
        ));
    }
}
