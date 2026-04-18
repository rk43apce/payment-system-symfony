<?php

namespace App\Controller;

use App\Response\ErrorResponse;
use App\Response\SuccessResponse;
use App\Service\TransferService;
use App\Validator\TransferValidator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TransferController extends AbstractController
{
    public function __construct(
        // Inject the dedicated 'transfer' monolog channel
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'monolog.logger.transfer')]
        private LoggerInterface $logger
    ) {}

    #[Route('/transfer', methods: ['POST'])]
    public function transfer(Request $request, TransferService $service, TransferValidator $validator): Response
    {
        $requestId = 'req_' . bin2hex(random_bytes(8));
        $startTime = microtime(true);

        $this->logger->info('Transfer request received', [
            'request_id'      => $requestId,
            'ip'              => $request->getClientIp(),
            'idempotency_key' => $request->headers->get('Idempotency-Key'),
        ]);

        try {
            $contentTypeError = $validator->validateContentType($request);
            if ($contentTypeError !== null) {
                $this->logger->warning('Invalid content type', [
                    'request_id'   => $requestId,
                    'content_type' => $request->headers->get('Content-Type'),
                ]);
                throw new \InvalidArgumentException($contentTypeError['message'], $contentTypeError['status']);
            }

            $data = $validator->validateJson($request->getContent());
            if (isset($data['code'])) {
                $this->logger->warning('Invalid JSON body', ['request_id' => $requestId]);
                throw new \InvalidArgumentException($data['message'], $data['status']);
            }

            $dtoOrError = $validator->validate($data, trim($request->headers->get('Idempotency-Key', '')));
            if (is_array($dtoOrError)) {
                $this->logger->warning('Transfer validation failed', [
                    'request_id' => $requestId,
                    'details'    => $dtoOrError['details'] ?? [],
                ]);
                return ErrorResponse::create('validation_error', $dtoOrError['message'], 'invalid_request_error', 422, $dtoOrError['details'] ?? [], $requestId);
            }

            $dto      = $dtoOrError;
            $transfer = $service->transferFunds($dto->sender_id, $dto->recipient_id, $dto->amount, $dto->idempotency_key);

            $ms = round((microtime(true) - $startTime) * 1000, 2);
            $this->logger->info('Transfer completed successfully', [
                'request_id'      => $requestId,
                'transfer_id'     => $transfer->getId(),
                'sender_id'       => $dto->sender_id,
                'recipient_id'    => $dto->recipient_id,
                'amount'          => round($dto->amount / 100, 2),
                'idempotency_key' => $dto->idempotency_key,
                'processing_ms'   => $ms,
            ]);

            return SuccessResponse::create([
                'id'           => $transfer->getId(),
                'sender_id'    => $transfer->getSender()->getId(),
                'recipient_id' => $transfer->getRecipient()->getId(),
                'amount'       => round($transfer->getAmount() / 100, 2),
                'status'       => $transfer->getStatus()->value,
                'created_at'   => $transfer->getCreatedAt()->format('Y-m-d H:i:s'),
            ], 201, $requestId);

        } catch (\InvalidArgumentException $e) {
            $ms = round((microtime(true) - $startTime) * 1000, 2);
            $this->logger->warning('Transfer validation error', [
                'request_id'    => $requestId,
                'error'         => $e->getMessage(),
                'processing_ms' => $ms,
            ]);
            return ErrorResponse::create('validation_error', $e->getMessage(), 'invalid_request_error', $e->getCode() ?: 422, [], $requestId);

        } catch (\RuntimeException $e) {
            $ms = round((microtime(true) - $startTime) * 1000, 2);
            $this->logger->warning('Transfer business error', [
                'request_id'    => $requestId,
                'error'         => $e->getMessage(),
                'processing_ms' => $ms,
            ]);
            return ErrorResponse::create('transfer_error', $e->getMessage(), 'invalid_request_error', 409, [], $requestId);

        } catch (\Throwable $e) {
            $ms = round((microtime(true) - $startTime) * 1000, 2);
            $this->logger->error('Transfer unexpected error', [
                'request_id'    => $requestId,
                'error'         => $e->getMessage(),
                'class'         => get_class($e),
                'file'          => $e->getFile(),
                'line'          => $e->getLine(),
                'processing_ms' => $ms,
            ]);
            return ErrorResponse::create('internal_server_error', 'Failed to process transfer. Please try again.', 'api_error', 500, [], $requestId);
        }
    }
}
