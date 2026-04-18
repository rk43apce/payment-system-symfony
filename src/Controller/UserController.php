<?php

namespace App\Controller;

use App\Exception\DuplicateUserException;
use App\Response\ErrorResponse;
use App\Response\SuccessResponse;
use App\Service\UserService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class UserController extends AbstractController
{
    public function __construct(private LoggerInterface $logger) {}

    #[Route('/users', methods: ['POST'])]
    public function create(Request $request, UserService $service): Response
    {
        $requestId = 'req_' . bin2hex(random_bytes(8));
        $startTime = microtime(true);

        $this->logger->info('Create user request received', [
            'request_id' => $requestId,
            'ip'         => $request->getClientIp(),
        ]);

        try {
            if (!str_contains($request->headers->get('Content-Type') ?? '', 'application/json')) {
                return ErrorResponse::create('invalid_request', 'Content-Type must be application/json', 'invalid_request_error', 400, [], $requestId);
            }

            $data = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ErrorResponse::create('invalid_json', 'Invalid JSON in request body', 'invalid_request_error', 400, [], $requestId);
            }

            $name = $data['name'] ?? '';
            $email = $data['email'] ?? '';

            $user = $service->createUser((string) $name, (string) $email);

            $ms = round((microtime(true) - $startTime) * 1000, 2);
            $this->logger->info('User created successfully', [
                'request_id'  => $requestId,
                'user_id'     => $user->getId(),
                'name'        => $user->getName(),
                'email'       => $user->getEmail(),
                'created_at'  => $user->getCreatedAt()->format('Y-m-d H:i:s'),
                'processing_ms' => $ms,
            ]);

            return SuccessResponse::create([
                'id'         => $user->getId(),
                'name'       => $user->getName(),
                'email'      => $user->getEmail(),
                'created_at' => $user->getCreatedAt()->format('Y-m-d H:i:s'),
            ], 201, $requestId);
        } catch (DuplicateUserException $e) {
            $this->logger->warning('Create user failed - duplicate email', ['request_id' => $requestId, 'error' => $e->getMessage()]);
            return ErrorResponse::create('duplicate_user', $e->getMessage(), 'conflict_error', 409, [], $requestId);
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('Create user validation failed', ['request_id' => $requestId, 'error' => $e->getMessage()]);
            return ErrorResponse::create('validation_error', $e->getMessage(), 'invalid_request_error', 422, [], $requestId);
        } catch (\Throwable $e) {
            $this->logger->error('Create user unexpected error', [
                'request_id' => $requestId,
                'error'      => $e->getMessage(),
                'class'      => get_class($e),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
            ]);
            return ErrorResponse::create('internal_server_error', 'Failed to create user.', 'api_error', 500, [], $requestId);
        }
    }

    #[Route('/users/{id}', methods: ['GET'])]
    public function get(int $id, UserService $service): Response
    {
        $requestId = 'req_' . bin2hex(random_bytes(8));

        try {
            $user = $service->getUser($id);

            return SuccessResponse::create([
                'id'         => $user->getId(),
                'name'       => $user->getName(),
                'email'      => $user->getEmail(),
                'created_at' => $user->getCreatedAt()->format('Y-m-d H:i:s'),
            ], 200, $requestId);
        } catch (\InvalidArgumentException $e) {
            return ErrorResponse::create('not_found', $e->getMessage(), 'invalid_request_error', 404, [], $requestId);
        } catch (\Throwable $e) {
            return ErrorResponse::create('internal_server_error', 'Failed to read user.', 'api_error', 500, [], $requestId);
        }
    }
}
