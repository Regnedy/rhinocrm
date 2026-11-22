<?php

namespace App\Modules\Analytics\Controllers;

use App\Modules\Analytics\Services\DashboardQueryService;
use App\Modules\Auth\DTOs\UserContextDTO;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DashboardController
{
    use ApiResponse;

    public function getSummary(
        Request $request,
        DashboardQueryService $service,
        UserContextDTO $context,
    ): JsonResponse {
        $period = (string) $request->query('period', date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            return $this->errorResponse('Periodo inválido.', 'PERIOD_INVALID', 422);
        }

        $data = match ($context->role) {
            'SUPER_ADMIN' => $service->getSuperAdminDashboard($period),
            'PARTNER_ADMIN', 'LOCATION_MANAGER', 'TEAM_LEADER' => $service->getPartnerDashboard($period),
            'ADVISOR' => $service->getAdvisorDashboard($period),
            default => null,
        };

        return $data === null
            ? $this->errorResponse('Rol no autorizado.', 'FORBIDDEN', 403)
            : $this->successResponse($data);
    }
}
