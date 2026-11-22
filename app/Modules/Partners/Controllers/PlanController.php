<?php

namespace App\Modules\Partners\Controllers;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class PlanController
{
    use ApiResponse;

    public function index(UserContextDTO $context): JsonResponse
    {
        if (!$context->isSuperAdmin()) {
            return $this->errorResponse('Solo SUPER_ADMIN puede consultar el catálogo de planes.', 'FORBIDDEN', 403);
        }

        return $this->successResponse(
            DB::table('plans')
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'max_users', 'max_devices', 'max_measurements', 'active'])
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
        );
    }
}
