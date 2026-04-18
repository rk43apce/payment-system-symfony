<?php

namespace App\Validator;

use App\DTO\TransferRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class TransferValidator
{
    public function __construct(private ValidatorInterface $validator) {}

    public function validateContentType(Request $request): ?array
    {
        if (!str_contains($request->headers->get('Content-Type') ?? '', 'application/json')) {
            return ['message' => 'Content-Type must be application/json', 'status' => 400];
        }
        return null;
    }

    public function validateJson(string $content): array
    {
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['code' => 'invalid_json', 'message' => 'Invalid JSON in request body', 'status' => 400];
        }
        return $data;
    }

    public function validate(array $data, string $idempotencyKey): TransferRequest|array
    {
        $dto = new TransferRequest();
        $dto->sender_id    = isset($data['sender_id'])    ? (int) $data['sender_id']                    : null;
        $dto->recipient_id = isset($data['recipient_id']) ? (int) $data['recipient_id']                 : null;
        $dto->amount       = isset($data['amount'])       ? (int) round((float) $data['amount'] * 100)  : null;
        $dto->idempotency_key = $idempotencyKey;

        $errors = $this->validator->validate($dto);
        if (count($errors) === 0) {
            return $dto;
        }

        $details = [];
        foreach ($errors as $error) {
            $details[$error->getPropertyPath()] = $error->getMessage();
        }

        return ['message' => 'Validation failed', 'status' => 422, 'details' => $details];
    }
}
