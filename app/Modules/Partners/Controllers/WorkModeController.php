<?php

namespace App\Modules\Partners\Controllers;

use App\Modules\Partners\Services\PartnerWorkModeService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkModeController
{
    use ApiResponse;

    public function show(PartnerWorkModeService $service): JsonResponse
    {
        return $this->successResponse($service->describe());
    }

    public function update(Request $request, PartnerWorkModeService $service): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:INDIVIDUAL,TEAM'],
        ]);

        return $this->successResponse(
            $service->setMode((string) $validated['mode']),
            'Modo de trabajo actualizado.',
        );
    }
}
