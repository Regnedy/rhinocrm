<?php

namespace App\Modules\Administration\Controllers;

use App\Modules\Administration\Services\DeviceUnassignmentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminDeviceController
{
    use ApiResponse;

    public function unassign(string $id, Request $request, DeviceUnassignmentService $service): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            return $this->successResponse(
                $service->unassign($id, $data['reason']),
                'Báscula desvinculada. Quedó disponible para asignarse a otro socio.',
            );
        } catch (\Throwable $exception) {
            $status = in_array((int) $exception->getCode(), [403, 404, 409, 422], true)
                ? (int) $exception->getCode()
                : 500;

            if ($status === 500) {
                report($exception);
            }

            return $this->errorResponse(
                $status === 500 ? 'No fue posible desvincular la báscula.' : $exception->getMessage(),
                'DEVICE_UNASSIGN_FAILED',
                $status,
            );
        }
    }
}
