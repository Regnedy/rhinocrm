<?php

namespace App\Modules\IoT\Controllers;

use App\Modules\IoT\Requests\RegisterDeviceRequest;
use App\Modules\IoT\Services\DeviceManagementService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Modules\Auth\DTOs\UserContextDTO;

final class DeviceController
{
    use ApiResponse;

    public function index(Request $request, UserContextDTO $context): JsonResponse
    {
        $partnerId = $context->partnerId ?? throw new \RuntimeException('Contexto sin socio.', 403);
        $query = DB::table('devices as d')->leftJoin('locations as l', 'l.id', '=', 'd.location_id')
            ->where('d.partner_id', $partnerId)->whereNull('d.deleted_at')
            ->select('d.id','d.mac_address','d.location_id','l.name as location_name','d.status','d.total_measurements','d.last_measurement_at','d.created_at');
        if ($request->filled('status')) $query->where('d.status', strtoupper((string)$request->query('status')));
        return $this->successResponse($query->orderByDesc('d.created_at')->limit(500)->get()->all());
    }

    public function store(RegisterDeviceRequest $request, DeviceManagementService $service): JsonResponse
    {
        try {
            return $this->successResponse(
                $service->register(
                    $request->validated()['mac_address'],
                    $request->validated()['location_id'] ?? null,
                ),
                'Dispositivo registrado. Guarde el secreto; no volverá a mostrarse.',
                201,
            );
        } catch (\Throwable $exception) {
            return $this->errorResponse(
                $exception->getMessage(),
                'DEVICE_REGISTRATION_FAILED',
                (int) $exception->getCode() === 409 ? 409 : 422,
            );
        }
    }
}
