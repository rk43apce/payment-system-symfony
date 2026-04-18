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
    #[Route('/transfer', methods: ['POST'])]
    public function transfer(
        Request $request,
        TransferService $service,
        TransferValidator $validator,
        LoggerInterface $logger
    ): Response {
        $requestId = 'req_' . bin2hex(random_bytes(8));

        try {
            $contentTypeError = $validator->validateContentType($request);
            if ($contentTypeError !== null) {
                throw new \InvalidArgumentException($contentTypeError['message'], $contentTypeError['status']);
            }

            $data = $validator->validateJson($request->getContent());
            if (isset($data['code'])) {
                throw new \InvalidArgumentException($data['message'], $data['status']);
            }

            $dtoOrError = $validator->validate($data, trim($request->headers->get('Idempotency-Key', '')));
            if (is_array($dtoOrError)) {
                return ErrorResponse::create(
                    'validation_error',
                    $dtoOrError['message'],
                    'invalid_request_error',
                    422,
                    $dtoOrError['details'] ?? [],
                    $requestId
                );
            }

            $dto = $dtoOrError;
            $transfer = $service->transferFunds(
                $dto->sender_id,
                $dto->recipient_id,
                $dto->amount,
                $dto->idempotency_key
            );

            $logger->info('Fund transfer completed', [
                'request_id'   => $requestId,
                'transfer_id'  => $transfer->getId(),
                'sender_id'    => $dto->sender_id,
                'recipient_id' => $dto->recipient_id,
                'amount'       => $dto->amount,
            ]);

            return SuccessResponse::create([
                'id'           => $transfer->getId(),
                'sender_id'    => $transfer->getSender()->getId(),
                'recipient_id' => $transfer->getRecipient()->getId(),
                'amount'       => round($transfer->getAmount() / 100, 2),
                'status'       => $transfer->getStatus(),
                'created_at'   => $transfer->getCreatedAt()->format('Y-m-d H:i:s'),
            ], 201, $requestId);

        } catch (\InvalidArgumentException $e) {
            $statusCode = $e->getCode() ?: 422;
            $logger->warning('Transfer validation error', [
                'request_id' => $requestId,
                'error'      => $e->getMessage(),
            ]);
            return ErrorResponse::create('validation_error', $e->getMessage(), 'invalid_request_error', $statusCode, [], $requestId);

        } catch (\RuntimeException $e) {
            $logger->warning('Transfer failed', ['request_id' => $requestId, 'error' => $e->getMessage()]);
            return ErrorResponse::create('transfer_error', $e->getMessage(), 'invalid_request_error', 409, [], $requestId);

        } catch (\Throwable $e) {
            $logger->error('Unexpected transfer error', [
                'request_id' => $requestId,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);
            return ErrorResponse::create('internal_server_error', 'Failed to process transfer. Please try again.', 'api_error', 500, [], $requestId);
        }
    }
}
