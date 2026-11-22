<?php
// Archivo: app/Traits/ApiResponse.php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

trait ApiResponse
{
    public function successResponse(mixed $data = [], string $message = 'Success', int $code = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $code);
    }

    public function errorResponse(string $message, string $errorCode, int $code = 400, mixed $details = null): JsonResponse
    {
        $res = ['success' => false, 'error' => ['code' => $errorCode, 'message' => $message]];
        if ($details) $res['error']['details'] = $details;
        return response()->json($res, $code);
    }
}