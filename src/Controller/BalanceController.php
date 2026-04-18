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
    #[Route('/balance/{user_id}', methods: ['GET'])]
    public function get(int $user_id, BalanceService $service, LoggerInterface $logger): Response
    {
        $requestId = 'req_' . bin2hex(random_bytes(8));

        try {
            $result = $service->getBalance($user_id);
            return SuccessResponse::create($result, 200, $requestId);

        } catch (\InvalidArgumentException $e) {
            $logger->warning('Balance check failed', ['request_id' => $requestId, 'error' => $e->getMessage()]);
            return ErrorResponse::create('not_found', $e->getMessage(), 'invalid_request_error', 404, [], $requestId);

        } catch (\Throwable $e) {
            $logger->error('Unexpected error fetching balance', ['request_id' => $requestId, 'error' => $e->getMessage()]);
            return ErrorResponse::create('internal_server_error', 'Failed to fetch balance.', 'api_error', 500, [], $requestId);
        }
    }

    #[Route('/balance/add', methods: ['POST'])]
    public function add(Request $request, BalanceService $service, LoggerInterface $logger): Response
    {
        $requestId = 'req_' . bin2hex(random_bytes(8));

        try {
            if (!str_contains($request->headers->get('Content-Type') ?? '', 'application/json')) {
                return ErrorResponse::create('invalid_request', 'Content-Type must be application/json', 'invalid_request_error', 400, [], $requestId);
            }

            $data = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ErrorResponse::create('invalid_json', 'Invalid JSON in request body', 'invalid_request_error', 400, [], $requestId);
            }

            $userId = isset($data['user_id']) ? (int)   $data['user_id']                    : null;
            $amount = isset($data['amount'])  ? (int) round((float) $data['amount'] * 100) : null;

            if (!$userId || $userId <= 0) {
                return ErrorResponse::create('validation_error', 'user_id is required and must be positive', 'invalid_request_error', 422, [], $requestId);
            }

            if (!$amount || $amount <= 0) {
                return ErrorResponse::create('validation_error', 'amount is required and must be positive', 'invalid_request_error', 422, [], $requestId);
            }

            $result = $service->addBalance($userId, $amount, trim($request->headers->get('Idempotency-Key', '')));

            $logger->info('Balance added', ['request_id' => $requestId, 'user_id' => $userId, 'amount' => $amount]);

            return SuccessResponse::create($result, 200, $requestId);

        } catch (\InvalidArgumentException $e) {
            $logger->warning('Add balance failed', ['request_id' => $requestId, 'error' => $e->getMessage()]);
            return ErrorResponse::create('not_found', $e->getMessage(), 'invalid_request_error', 404, [], $requestId);

        } catch (\Throwable $e) {
            $logger->error('Unexpected error adding balance', ['request_id' => $requestId, 'error' => $e->getMessage()]);
            return ErrorResponse::create('internal_server_error', 'Failed to add balance.', 'api_error', 500, [], $requestId);
        }
    }
}
