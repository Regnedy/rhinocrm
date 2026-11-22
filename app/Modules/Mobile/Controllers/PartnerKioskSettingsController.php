<?php

namespace App\Modules\Mobile\Controllers;

use App\Modules\Mobile\Services\PartnerKioskSettingsService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PartnerKioskSettingsController
{
    use ApiResponse;

    public function show(Request $request, PartnerKioskSettingsService $service): JsonResponse
    {
        return $this->successResponse($service->show($request->query('partner_id')));
    }

    public function update(Request $request, PartnerKioskSettingsService $service): JsonResponse
    {
        $data = $request->validate([
            'partner_id' => ['nullable', 'string', 'max:64'],
            'whatsapp_waiting_template' => ['required', 'string', 'max:2000'],
            'whatsapp_waiting_deadline' => ['required', 'string', 'max:60'],
        ]);
        return $this->successResponse(
            $service->save($data, $data['partner_id'] ?? $request->query('partner_id')),
            'Mensaje de espera actualizado para todos los Kioscos del socio.',
        );
    }

    public function reset(Request $request, PartnerKioskSettingsService $service): JsonResponse
    {
        $data = $request->validate(['partner_id' => ['nullable', 'string', 'max:64']]);
        return $this->successResponse(
            $service->reset($data['partner_id'] ?? $request->query('partner_id')),
            'Mensaje de espera restaurado al valor predeterminado.',
        );
    }
}
