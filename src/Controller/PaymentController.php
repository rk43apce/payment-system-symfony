<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

use App\Service\PaymentService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Response\ErrorResponse;


use App\Response\SuccessResponse;
use Psr\Log\LoggerInterface;
use App\Validator\PaymentValidator;


final class PaymentController extends AbstractController
{

    #[Route('/payment', methods: ['POST'])]
    public function create(
        Request $request,
        PaymentService $service,
        PaymentValidator $validator,
        LoggerInterface $logger
    ): Response {
        $startTime = microtime(true);
        $requestId = uniqid('req_', true);

        // Log incoming request
        $logger->info('Payment creation request started', [
            'request_id' => $requestId,
            'method' => $request->getMethod(),
            'url' => $request->getUri(),
            'content_type' => $request->headers->get('Content-Type'),
            'content_length' => $request->headers->get('Content-Length'),
            'user_agent' => $request->headers->get('User-Agent'),
            'ip' => $request->getClientIp(),
        ]);

        try {
            // 1. Validate content type
            $contentTypeError = $validator->validateContentType($request);
            if ($contentTypeError !== null) {
                throw new \InvalidArgumentException($contentTypeError['message'], $contentTypeError['status']);
            }

            // 2. Parse and validate JSON
            $data = $validator->validateJson($request->getContent());
            if (is_array($data) && isset($data['code'])) {
                throw new \InvalidArgumentException($data['message'], $data['status']);
            }

            // 3. Map and validate payment data
            $dtoOrError = $validator->mapAndValidatePaymentData($data);
            if (is_array($dtoOrError)) {
                throw new \InvalidArgumentException($dtoOrError['message'], $dtoOrError['status']);
            }

            $dto = $dtoOrError;

            // 4. Create payment
            $payment = $service->createPayment(
                $dto->reference_id,
                $dto->amount
            );

            if (!$payment) {
                throw new \RuntimeException('Payment service returned null');
            }

            // 5. Return success response
            $response = SuccessResponse::create([
                'id' => $payment->getId(),
                'reference_id' => $payment->getReferenceId(),
                'amount' => $payment->getAmount(),
                'status' => $payment->getStatus(),
                'created_at' => $payment->getCreatedAt()->format('Y-m-d H:i:s'),
            ], 201, $requestId);

            $processingTime = microtime(true) - $startTime;
            $logger->info('Payment creation completed successfully', [
                'request_id' => $requestId,
                'payment_id' => $payment->getId(),
                'reference_id' => $payment->getReferenceId(),
                'amount' => $payment->getAmount(),
                'status' => $payment->getStatus(),
                'response_status' => 201,
                'processing_time_ms' => round($processingTime * 1000, 2),
            ]);

            return $response;

        } catch (\InvalidArgumentException $e) {
            $statusCode = $e->getCode() ?: 422;
            $processingTime = microtime(true) - $startTime;

            $logger->warning('Validation error during payment creation', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'status' => $statusCode,
                'request_body' => json_encode($data ?? []),
                'processing_time_ms' => round($processingTime * 1000, 2),
            ]);

            $response = ErrorResponse::create(
                'validation_error',
                $e->getMessage(),
                'invalid_request_error',
                $statusCode,
                [],
                $requestId
            );

            $logger->info('Payment creation failed - validation error response sent', [
                'request_id' => $requestId,
                'response_status' => $statusCode,
                'error_code' => 'validation_error',
            ]);

            return $response;

        } catch (\App\Exception\PaymentNotFoundException $e) {
            $processingTime = microtime(true) - $startTime;
            $logger->warning('Payment not found during creation', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'reference_id' => $dto->reference_id ?? null,
                'processing_time_ms' => round($processingTime * 1000, 2),
            ]);

            $response = ErrorResponse::create(
                'payment_error',
                $e->getMessage(),
                'invalid_request_error',
                404,
                [],
                $requestId
            );

            $logger->info('Payment creation failed - payment not found response sent', [
                'request_id' => $requestId,
                'response_status' => 404,
                'error_code' => 'payment_error',
            ]);

            return $response;

        } catch (\Throwable $e) {
            $processingTime = microtime(true) - $startTime;
            $logger->error('Unexpected error during payment creation', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_body' => json_encode($data ?? []),
                'processing_time_ms' => round($processingTime * 1000, 2),
            ]);

            $response = ErrorResponse::create(
                'internal_server_error',
                'Failed to create payment. Please try again.',
                'api_error',
                500,
                [],
                $requestId
            );

            $logger->info('Payment creation failed - internal error response sent', [
                'request_id' => $requestId,
                'response_status' => 500,
                'error_code' => 'internal_server_error',
            ]);

            return $response;
        }
    }

    #[Route('/payment/process', methods: ['POST'])]
    public function process(
        Request $request,
        PaymentService $service,
        PaymentValidator $validator,
        LoggerInterface $logger
    ): Response {
        $startTime = microtime(true);
        $requestId = uniqid('req_', true);

        // Log incoming request
        $logger->info('Payment processing request started', [
            'request_id' => $requestId,
            'method' => $request->getMethod(),
            'url' => $request->getUri(),
            'content_type' => $request->headers->get('Content-Type'),
            'content_length' => $request->headers->get('Content-Length'),
            'user_agent' => $request->headers->get('User-Agent'),
            'ip' => $request->getClientIp(),
        ]);

        try {
            // 1. Validate content type
            $contentTypeError = $validator->validateContentType($request);
            if ($contentTypeError !== null) {
                throw new \InvalidArgumentException($contentTypeError['message'], $contentTypeError['status']);
            }

            // 2. Parse and validate JSON
            $data = $validator->validateJson($request->getContent());
            if (is_array($data) && isset($data['code'])) {
                throw new \InvalidArgumentException($data['message'], $data['status']);
            }

            // 3. Validate reference ID
            $referenceId = $data['reference_id'] ?? null;
            $validationError = $validator->validateReferenceId($referenceId);
            if ($validationError !== null) {
                throw new \InvalidArgumentException($validationError['message'], $validationError['status']);
            }

            // 4. Process payment
            $payment = $service->processPayment($referenceId);

            // 5. Return success response
            $response = SuccessResponse::create([
                'reference_id' => $referenceId,
                'status' => $payment->getStatus()
            ], 200, $requestId);

            $processingTime = microtime(true) - $startTime;
            $logger->info('Payment processing completed successfully', [
                'request_id' => $requestId,
                'reference_id' => $referenceId,
                'status' => $payment->getStatus(),
                'response_status' => 200,
                'processing_time_ms' => round($processingTime * 1000, 2),
            ]);

            return $response;

        } catch (\InvalidArgumentException $e) {
            $statusCode = $e->getCode() ?: 422;
            $processingTime = microtime(true) - $startTime;

            $logger->warning('Validation error during payment processing', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'status' => $statusCode,
                'request_body' => json_encode($data ?? []),
                'processing_time_ms' => round($processingTime * 1000, 2),
            ]);

            $response = ErrorResponse::create(
                'validation_error',
                $e->getMessage(),
                'invalid_request_error',
                $statusCode,
                [],
                $requestId
            );

            $logger->info('Payment processing failed - validation error response sent', [
                'request_id' => $requestId,
                'response_status' => $statusCode,
                'error_code' => 'validation_error',
            ]);

            return $response;

        } catch (\Throwable $e) {
            $processingTime = microtime(true) - $startTime;
            $logger->error('Error processing payment', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_body' => json_encode($data ?? []),
                'processing_time_ms' => round($processingTime * 1000, 2),
            ]);

            $response = ErrorResponse::create(
                'payment_error',
                $e->getMessage(),
                'api_error',
                500,
                [],
                $requestId
            );

            $logger->info('Payment processing failed - error response sent', [
                'request_id' => $requestId,
                'response_status' => 500,
                'error_code' => 'payment_error',
            ]);

            return $response;
        }
    }



    #[Route('/payment/{reference_id}', methods: ['GET'])]
    public function get(
        string $reference_id,
        PaymentService $service,
        PaymentValidator $validator,
        LoggerInterface $logger
    ): Response {
        $startTime = microtime(true);
        $requestId = uniqid('req_', true);

        // Log incoming request
        $logger->info('Payment retrieval request started', [
            'request_id' => $requestId,
            'method' => 'GET',
            'url' => "/payment/{$reference_id}",
            'reference_id' => $reference_id,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);

        try {
            // 1. Validate reference ID
            $validationError = $validator->validateReferenceId($reference_id);
            if ($validationError !== null) {
                throw new \InvalidArgumentException($validationError['message'], $validationError['status']);
            }

            // 2. Fetch payment
            $payment = $service->getPayment($reference_id);

            // 3. Return success response
            $response = SuccessResponse::create([
                'reference_id' => $payment->getReferenceId(),
                'amount' => $payment->getAmount(),
                'status' => $payment->getStatus(),
                'created_at' => $payment->getCreatedAt()->format('Y-m-d H:i:s'),
            ], 200, $requestId);

            $processingTime = microtime(true) - $startTime;
            $logger->info('Payment retrieval completed successfully', [
                'request_id' => $requestId,
                'reference_id' => $reference_id,
                'payment_id' => $payment->getId(),
                'amount' => $payment->getAmount(),
                'status' => $payment->getStatus(),
                'response_status' => 200,
                'processing_time_ms' => round($processingTime * 1000, 2),
            ]);

            return $response;

        } catch (\InvalidArgumentException $e) {
            $statusCode = $e->getCode() ?: 422;
            $processingTime = microtime(true) - $startTime;

            $logger->warning('Validation error during payment retrieval', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'reference_id' => $reference_id,
                'status' => $statusCode,
                'processing_time_ms' => round($processingTime * 1000, 2),
            ]);

            $response = ErrorResponse::create(
                'validation_error',
                $e->getMessage(),
                'invalid_request_error',
                $statusCode,
                [],
                $requestId
            );

            $logger->info('Payment retrieval failed - validation error response sent', [
                'request_id' => $requestId,
                'response_status' => $statusCode,
                'error_code' => 'validation_error',
            ]);

            return $response;

        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            $processingTime = microtime(true) - $startTime;
            $logger->warning('Payment not found', [
                'request_id' => $requestId,
                'reference_id' => $reference_id,
                'processing_time_ms' => round($processingTime * 1000, 2),
            ]);

            $response = ErrorResponse::create(
                'payment_not_found',
                $e->getMessage(),
                'invalid_request_error',
                404,
                [],
                $requestId
            );

            $logger->info('Payment retrieval failed - not found response sent', [
                'request_id' => $requestId,
                'response_status' => 404,
                'error_code' => 'payment_not_found',
            ]);

            return $response;

        } catch (\Throwable $e) {
            $processingTime = microtime(true) - $startTime;
            $logger->error('Unexpected error fetching payment', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'reference_id' => $reference_id,
                'trace' => $e->getTraceAsString(),
                'processing_time_ms' => round($processingTime * 1000, 2),
            ]);

            $response = ErrorResponse::create(
                'internal_error',
                'Something went wrong',
                'api_error',
                500,
                [],
                $requestId
            );

            $logger->info('Payment retrieval failed - internal error response sent', [
                'request_id' => $requestId,
                'response_status' => 500,
                'error_code' => 'internal_error',
            ]);

            return $response;
        }
    }

}
