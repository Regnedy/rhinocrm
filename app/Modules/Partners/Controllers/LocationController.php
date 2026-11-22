<?php

namespace App\Modules\Partners\Controllers;

use App\Modules\Partners\Requests\CreateLocationRequest;
use App\Modules\Partners\Services\LocationManagementService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LocationController
{
    use ApiResponse;

    public function index(Request $request, LocationManagementService $service): JsonResponse
    {
        try {
            return $this->successResponse($service->list($request->query('partner_id')));
        } catch (\Throwable $exception) {
            return $this->errorResponse(
                $exception->getMessage(),
                'LOCATION_LIST_FAILED',
                (int) $exception->getCode() === 403 ? 403 : 422,
            );
        }
    }

    public function store(CreateLocationRequest $request, LocationManagementService $service): JsonResponse
    {
        try {
            return $this->successResponse(
                $service->create($request->validated(), $request->query('partner_id')),
                'Sucursal creada.',
                201,
            );
        } catch (\Throwable $exception) {
            $status = in_array((int) $exception->getCode(), [403, 409], true)
                ? (int) $exception->getCode()
                : 422;

            return $this->errorResponse($exception->getMessage(), 'LOCATION_CREATE_FAILED', $status);
        }
    }
}
