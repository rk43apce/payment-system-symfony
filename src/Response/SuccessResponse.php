<?php

namespace App\Response;

use Symfony\Component\HttpFoundation\JsonResponse;

class SuccessResponse
{
    public static function create(
        array $data = [],
        int $status = 200,
        ?string $requestId = null
    ): JsonResponse {
        $response = [
            'success' => true,
            'data' => $data
        ];

        if ($requestId !== null) {
            $response['request_id'] = $requestId;
        }

        return new JsonResponse($response, $status);
    }
}