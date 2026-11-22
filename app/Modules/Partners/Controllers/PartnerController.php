<?php

namespace App\Modules\Partners\Controllers;

use App\Modules\Partners\Requests\CreatePartnerRequest;
use App\Modules\Partners\Services\PartnerProvisioningService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

final class PartnerController
{
    use ApiResponse;

    public function store(CreatePartnerRequest $request, PartnerProvisioningService $service): JsonResponse
    {
        try {
            return $this->successResponse(
                $service->create($request->validated()),
                'Partner creado.',
                201,
            );
        } catch (\Throwable $exception) {
            $status = in_array((int) $exception->getCode(), [403, 404, 409, 422], true)
                ? (int) $exception->getCode()
                : 422;

            return $this->errorResponse($exception->getMessage(), 'PARTNER_CREATE_FAILED', $status);
        }
    }
}
