<?php

namespace App\Modules\Analytics\Controllers;

use App\Modules\Analytics\Services\DashboardGadgetService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DashboardGadgetController
{
    use ApiResponse;

    public function workspace(Request $request, DashboardGadgetService $service): JsonResponse
    {
        $period = (string) $request->query('period', date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            return $this->errorResponse('Periodo inválido.', 'PERIOD_INVALID', 422);
        }

        try {
            return $this->successResponse($service->workspace($period));
        } catch (\Throwable $exception) {
            report($exception);
            return $this->errorResponse('No fue posible cargar el dashboard personalizable.', 'DASHBOARD_GADGETS_ERROR', 500);
        }
    }

    public function saveLayout(Request $request, DashboardGadgetService $service): JsonResponse
    {
        $data = $request->validate([
            'layout' => ['required', 'array', 'max:40'],
            'layout.*.code' => ['required', 'string', 'max:80'],
            'layout.*.enabled' => ['nullable', 'boolean'],
            'layout.*.width' => ['nullable', 'integer', 'in:3,4,6,8,12'],
        ]);

        try {
            return $this->successResponse(
                $service->saveLayout($data['layout']),
                'Dashboard actualizado.',
            );
        } catch (\Throwable $exception) {
            report($exception);
            return $this->errorResponse('No fue posible guardar el dashboard.', 'DASHBOARD_LAYOUT_ERROR', 500);
        }
    }
}
