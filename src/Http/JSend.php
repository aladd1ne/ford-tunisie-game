<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

class JSend
{
    public static function success(string $message, array $data = [], int $code = 200): JsonResponse
    {
        return new JsonResponse([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    public static function error(string $message, int|string $customCode = 0, int $code = 400, array $data = []): JsonResponse
    {
        return new JsonResponse([
            'status' => 'error',
            'message' => $message,
            'code' => (int) $customCode,
            'data' => $data,
        ], $code);
    }

    public static function fail(string $message, array $data = [], string|int $customCode = 0, int $code = 400): JsonResponse
    {
        return new JsonResponse([
            'status' => 'fail',
            'message' => $message,
            'data' => $data,
            'code' => (int) $customCode,
        ], $code);
    }
}