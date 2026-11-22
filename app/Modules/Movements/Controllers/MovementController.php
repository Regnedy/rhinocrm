<?php

namespace App\Modules\Movements\Controllers;

use App\Modules\Movements\Services\MovementQueryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MovementController
{
    use ApiResponse;

    public function types(MovementQueryService $service): JsonResponse
    {
        return $this->successResponse($service->types());
    }

    public function index(Request $request, MovementQueryService $service): JsonResponse
    {
        return $this->successResponse($service->list($request->only([
            'partner_id',
            'movement_code',
            'module',
            'folio',
            'target_id',
            'date_from',
            'date_to',
            'cursor',
            'limit',
        ])));
    }
}
