<?php

namespace App\Modules\Integrations\Whatsapp\Controllers;

use App\Modules\Integrations\Whatsapp\Services\PartnerWhatsappChannelService;
use App\Modules\Integrations\Whatsapp\Services\PartnerWhatsappEmbeddedSignupService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PartnerWhatsappChannelController
{
    use ApiResponse;

    public function show(Request $request, PartnerWhatsappChannelService $service): JsonResponse
    {
        return $this->successResponse($service->show($request->query('partner_id')));
    }

    public function embeddedSignupConfig(Request $request, PartnerWhatsappEmbeddedSignupService $service): JsonResponse
    {
        return $this->successResponse($service->publicConfig($request->query('partner_id')));
    }

    public function completeEmbeddedSignup(Request $request, PartnerWhatsappEmbeddedSignupService $service): JsonResponse
    {
        $data = $request->validate([
            'partner_id' => ['nullable', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:4096'],
            'waba_id' => ['nullable', 'string', 'max:100'],
            'phone_number_id' => ['nullable', 'string', 'max:100'],
        ]);
        return $this->successResponse(
            $service->complete($data, $data['partner_id'] ?? $request->query('partner_id')),
            'WhatsApp se vinculó correctamente con Meta.',
        );
    }

    public function update(Request $request, PartnerWhatsappChannelService $service): JsonResponse
    {
        $data = $request->validate([
            'partner_id' => ['nullable', 'string', 'max:64'],
            'display_phone_number' => ['nullable', 'string', 'max:40'],
            'phone_number_id' => ['required', 'string', 'max:100'],
            'waba_id' => ['nullable', 'string', 'max:100'],
            'access_token' => ['nullable', 'string', 'max:4096'],
            'pdf_template_name' => ['nullable', 'string', 'max:120'],
            'images_template_name' => ['nullable', 'string', 'max:120'],
            'template_language' => ['nullable', 'string', 'max:16', 'regex:/^[a-z]{2}(?:_[A-Z]{2})?$/'],
        ]);
        return $this->successResponse(
            $service->save($data, $data['partner_id'] ?? $request->query('partner_id')),
            'Configuración de WhatsApp actualizada.',
        );
    }

    public function test(Request $request, PartnerWhatsappChannelService $service): JsonResponse
    {
        $data = $request->validate(['partner_id' => ['nullable', 'string', 'max:64']]);
        return $this->successResponse(
            $service->testConnection($data['partner_id'] ?? $request->query('partner_id')),
            'Conexión con Meta verificada.',
        );
    }

    public function disable(Request $request, PartnerWhatsappChannelService $service): JsonResponse
    {
        $data = $request->validate(['partner_id' => ['nullable', 'string', 'max:64']]);
        return $this->successResponse(
            $service->disable($data['partner_id'] ?? $request->query('partner_id')),
            'Canal WhatsApp desactivado.',
        );
    }
}
