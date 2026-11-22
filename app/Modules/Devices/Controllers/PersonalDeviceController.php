<?php

namespace App\Modules\Devices\Controllers;

use App\Modules\Devices\Services\PersonalDeviceService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PersonalDeviceController
{
    use ApiResponse;

    public function index(PersonalDeviceService $service): JsonResponse { return $this->respond(fn () => $service->mine()); }

    public function store(Request $request, PersonalDeviceService $service): JsonResponse
    {
        $data = $request->validate([
            'device_type' => ['required', 'in:SCALE,PRINTER'],
            'display_name' => ['nullable', 'string', 'max:180'],
            'hardware_identifier' => ['nullable', 'string', 'max:180'],
            'model' => ['nullable', 'string', 'max:120'],
            'metadata' => ['nullable', 'array'],
        ]);
        return $this->respond(fn () => $service->store($data), 'Dispositivo configurado.', 201);
    }

    public function destroy(string $id, PersonalDeviceService $service): JsonResponse { return $this->respond(fn () => $service->remove($id), 'Dispositivo retirado.'); }
    public function campaign(string $id, PersonalDeviceService $service): JsonResponse { return $this->respond(fn () => $service->forCampaign($id)); }

    public function share(string $id, Request $request, PersonalDeviceService $service): JsonResponse
    {
        $data = $request->validate(['personal_device_ids' => ['present', 'array', 'max:20'], 'personal_device_ids.*' => ['string', 'max:80']]);
        return $this->respond(fn () => $service->shareForCampaign($id, (array) $data['personal_device_ids']), 'Dispositivos disponibles en el evento.');
    }

    private function respond(callable $callback, string $message = 'Success', int $status = 200): JsonResponse
    {
        try { return $this->successResponse($callback(), $message, $status); }
        catch (\Throwable $e) {
            $code = (int) $e->getCode(); $http = in_array($code, [403,404,409,422], true) ? $code : 500;
            if ($http === 500) report($e);
            return $this->errorResponse($http === 500 ? 'No fue posible gestionar el dispositivo.' : $e->getMessage(), 'PERSONAL_DEVICE_FAILED', $http);
        }
    }
}
