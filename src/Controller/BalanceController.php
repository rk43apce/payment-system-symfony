<?php

namespace App\Controller;

use App\Response\ErrorResponse;
use App\Response\SuccessResponse;
use App\Service\BalanceService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BalanceController extends AbstractController
{
    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'monolog.logger.balance')]
        private LoggerInterface $logger
    ) {}

    #[Route('/balance/{user_id}', methods: ['GET'])]
    public function get(int $user_id, BalanceService $service): Response
    {
        $requestId = 'req_' . bin2hex(random_bytes(8));
        $startTime = microtime(true);

        $this->logger->info('Balance check request', [
            'request_id' => $requestId,
            'user_id'    => $user_id,
        ]);

        try {
            $result = $service->getBalance($user_id);

            $this->logger->info('Balance check completed', [
                'request_id'    => $requestId,
                'user_id'       => $user_id,
                'balance'       => $result['balance'],
                'processing_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);

            return SuccessResponse::create($result, 200, $requestId);

        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('Balance check failed - user not found', [
                'request_id' => $requestId,
                'user_id'    => $user_id,
                'error'      => $e->getMessage(),
            ]);
            return ErrorResponse::create('not_found', $e->getMessage(), 'invalid_request_error', 404, [], $requestId);

        } catch (\Throwable $e) {
            $this->logger->error('Balance check unexpected error', [
                'request_id' => $requestId,
                'user_id'    => $user_id,
                'error'      => $e->getMessage(),
                'class'      => get_class($e),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
            ]);
            return ErrorResponse::create('internal_server_error', 'Failed to fetch balance.', 'api_error', 500, [], $requestId);
        }
    }

    #[Route('/balance/add', methods: ['POST'])]
    public function add(Request $request, BalanceService $service): Response
    {
        $requestId = 'req_' . bin2hex(random_bytes(8));
        $startTime = microtime(true);

        $this->logger->info('Add balance request received', [
            'request_id'      => $requestId,
            'ip'              => $request->getClientIp(),
            'idempotency_key' => $request->headers->get('Idempotency-Key'),
        ]);

        try {
            if (!str_contains($request->headers->get('Content-Type') ?? '', 'application/json')) {
                $this->logger->warning('Invalid content type on add balance', ['request_id' => $requestId]);
                return ErrorResponse::create('invalid_request', 'Content-Type must be application/json', 'invalid_request_error', 400, [], $requestId);
            }

            $data = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->warning('Invalid JSON on add balance', ['request_id' => $requestId]);
                return ErrorResponse::create('invalid_json', 'Invalid JSON in request body', 'invalid_request_error', 400, [], $requestId);
            }

            $userId = isset($data['user_id']) ? (int) $data['user_id']                    : null;
            $amount = isset($data['amount'])  ? (int) round((float) $data['amount'] * 100) : null;

            if (!$userId || $userId <= 0) {
                $this->logger->warning('Add balance validation failed', ['request_id' => $requestId, 'field' => 'user_id']);
                return ErrorResponse::create('validation_error', 'user_id is required and must be positive', 'invalid_request_error', 422, [], $requestId);
            }

            if (!$amount || $amount <= 0) {
                $this->logger->warning('Add balance validation failed', ['request_id' => $requestId, 'field' => 'amount']);
                return ErrorResponse::create('validation_error', 'amount is required and must be positive', 'invalid_request_error', 422, [], $requestId);
            }

            $result = $service->addBalance($userId, $amount, trim($request->headers->get('Idempotency-Key', '')));

            $ms = round((microtime(true) - $startTime) * 1000, 2);
            $this->logger->info('Balance added successfully', [
                'request_id'      => $requestId,
                'user_id'         => $userId,
                'amount'          => round($amount / 100, 2),
                'new_balance'     => $result['balance'],
                'idempotency_key' => $request->headers->get('Idempotency-Key'),
                'processing_ms'   => $ms,
            ]);

            return SuccessResponse::create($result, 200, $requestId);

        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('Add balance failed', [
                'request_id' => $requestId,
                'error'      => $e->getMessage(),
            ]);
            return ErrorResponse::create('not_found', $e->getMessage(), 'invalid_request_error', 404, [], $requestId);

        } catch (\Throwable $e) {
            $this->logger->error('Add balance unexpected error', [
                'request_id' => $requestId,
                'error'      => $e->getMessage(),
                'class'      => get_class($e),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
            ]);
            return ErrorResponse::create('internal_server_error', 'Failed to add balance.', 'api_error', 500, [], $requestId);
        }
    }
}
