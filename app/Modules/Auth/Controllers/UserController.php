<?php

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Requests\CreateUserRequest;
use App\Modules\Auth\Services\UserAdminService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

final class UserController
{
    use ApiResponse;

    public function store(CreateUserRequest $request, UserAdminService $service): JsonResponse
    {
        try {
            $user = $service->create($request->validated());

            return $this->successResponse([
                'id' => $user->getId(),
                'partner_id' => $user->getPartnerId(),
                'role' => $user->getRole(),
                'email' => $user->getEmail(),
            ], 'Usuario creado.', 201);
        } catch (\Throwable $exception) {
            return $this->errorResponse($exception->getMessage(), 'USER_CREATE_FAILED', 422);
        }
    }
}
