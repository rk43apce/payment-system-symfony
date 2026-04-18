<?php

namespace App\Validator;

use App\DTO\CreatePaymentRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class PaymentValidator
{
    public function __construct(
        private ValidatorInterface $validator
    ) {}

    /**
     * Validate request content type
     */
    public function validateContentType(Request $request): ?array
    {
        $contentType = $request->headers->get('Content-Type');
        if (!str_contains($contentType ?? '', 'application/json')) {
            return [
                'code' => 'invalid_request_error',
                'message' => 'Content-Type must be application/json',
                'type' => 'invalid_request_error',
                'status' => 400
            ];
        }

        return null;
    }

    /**
     * Validate JSON parsing
     */
    public function validateJson(string $content): array|string
    {
        $data = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'code' => 'invalid_request_error',
                'message' => 'Invalid JSON in request body',
                'type' => 'invalid_request_error',
                'status' => 400
            ];
        }

        return $data;
    }

    /**
     * Validate DTO and return errors if any
     */
    public function validateDTO(CreatePaymentRequest $dto): ?array
    {
        $errors = $this->validator->validate($dto);

        if (count($errors) === 0) {
            return null;
        }

        $errorDetails = [];
        foreach ($errors as $error) {
            $field = $error->getPropertyPath();
            $errorDetails[$field] = $error->getMessage();
        }

        return [
            'code' => 'validation_error',
            'message' => 'Validation failed',
            'type' => 'invalid_request_error',
            'status' => 422,
            'details' => $errorDetails
        ];
    }

    /**
     * Map and validate payment data to DTO
     */
    public function mapAndValidatePaymentData(array $data): CreatePaymentRequest|array
    {
        $dto = new CreatePaymentRequest();
        $dto->reference_id = $data['reference_id'] ?? null;
        $dto->amount = $data['amount'] ?? null;

        $validationError = $this->validateDTO($dto);
        if ($validationError !== null) {
            return $validationError;
        }

        return $dto;
    }

    /**
     * Validate reference ID
     */
    public function validateReferenceId(?string $referenceId): ?array
    {
        if (empty($referenceId)) {
            return [
                'code' => 'validation_error',
                'message' => 'reference_id is required',
                'type' => 'invalid_request_error',
                'status' => 422
            ];
        }

        return null;
    }
}
