<?php

namespace App\Response;

use Symfony\Component\HttpFoundation\JsonResponse;

class ErrorResponse
{
    public static function create(
        string $code,
        string $message,
        string $type = 'invalid_request_error',
        int $status = 400,
        array $details = [],
        ?string $requestId = null
    ): JsonResponse {
        $response = [
            'error' => [
                'code' => $code,
                'message' => $message,
                'type' => $type,
            ]
        ];

        if (!empty($details)) {
            $response['error']['details'] = $details;
        }

        if ($requestId !== null) {
            $response['request_id'] = $requestId;
        }

        return new JsonResponse($response, $status);
    }
}
