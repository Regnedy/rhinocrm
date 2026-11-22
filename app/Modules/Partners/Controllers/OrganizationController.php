<?php

namespace App\Modules\Partners\Controllers;

use App\Modules\Partners\Services\PartnerCredentialVerificationService;
use App\Modules\Partners\Services\PartnerOrganizationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrganizationController
{
    use ApiResponse;

    public function ranks(Request $request, PartnerOrganizationService $service): JsonResponse
    {
        return $this->successResponse($service->ranks($request->boolean('include_inactive')));
    }

    public function workspace(Request $request, PartnerOrganizationService $service): JsonResponse
    {
        return $this->successResponse($service->workspace($request->query('partner_id')));
    }

    public function support(Request $request, PartnerOrganizationService $service): JsonResponse
    {
        return $this->successResponse($service->supportRequests($request->query('partner_id')));
    }

    public function createSupport(Request $request, PartnerOrganizationService $service): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:5000'],
            'priority' => ['nullable', 'string', 'in:LOW,NORMAL,HIGH,URGENT'],
        ]);
        return $this->successResponse($service->createSupportRequest($data), 'Solicitud de apoyo creada.', 201);
    }

    public function escalateSupport(string $id, Request $request, PartnerOrganizationService $service): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:3000']]);
        return $this->successResponse($service->escalateSupport($id, $data['note'] ?? null), 'Solicitud escalada.');
    }

    public function resolveSupport(string $id, Request $request, PartnerOrganizationService $service): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:3000']]);
        return $this->successResponse($service->resolveSupport($id, $data['note'] ?? null), 'Solicitud resuelta.');
    }

    public function verifyCredential(string $publicCode, PartnerCredentialVerificationService $service): JsonResponse
    {
        return $this->successResponse($service->verify($publicCode));
    }
}
