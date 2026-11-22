<?php

namespace App\Modules\Partners\Controllers;

use App\Modules\Partners\Services\ContactPartnerTransferService;
use App\Modules\Partners\Services\PartnerOrganizationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminOrganizationController
{
    use ApiResponse;

    public function updateIdentity(string $id, Request $request, PartnerOrganizationService $service): JsonResponse
    {
        $data = $request->validate([
            'partner_number' => ['sometimes', 'required', 'string', 'regex:/^\d{1,40}$/'],
            'business_name' => ['sometimes', 'required', 'string', 'max:180'],
            'sponsor_partner_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'current_rank_id' => ['sometimes', 'required', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        return $this->successResponse($service->updateIdentity($id, $data), 'Datos organizacionales actualizados.');
    }

    public function storeRank(Request $request, PartnerOrganizationService $service): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:120'],
            'hierarchy_order' => ['required', 'integer', 'min:1', 'max:65000'],
            'credential_label' => ['nullable', 'string', 'max:120'],
            'badge_key' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:3000'],
            'active' => ['nullable', 'boolean'],
        ]);
        return $this->successResponse($service->createRank($data), 'Rango creado.', 201);
    }

    public function updateRank(string $id, Request $request, PartnerOrganizationService $service): JsonResponse
    {
        $data = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:60'],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'hierarchy_order' => ['sometimes', 'required', 'integer', 'min:1', 'max:65000'],
            'credential_label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'badge_key' => ['sometimes', 'nullable', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'active' => ['sometimes', 'boolean'],
        ]);
        return $this->successResponse($service->updateRank($id, $data), 'Rango actualizado.');
    }

    public function issueCredential(string $id, Request $request, PartnerOrganizationService $service): JsonResponse
    {
        $data = $request->validate(['expires_at' => ['nullable', 'date']]);
        return $this->successResponse($service->issueCredential($id, $data['expires_at'] ?? null), 'Credencial emitida.', 201);
    }

    public function revokeCredential(string $id, Request $request, PartnerOrganizationService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        return $this->successResponse($service->revokeCredential($id, $data['reason']), 'Credencial revocada.');
    }

    public function contacts(string $id, Request $request, ContactPartnerTransferService $service): JsonResponse
    {
        return $this->successResponse($service->contacts(
            $id,
            (string) $request->query('search', ''),
            (int) $request->query('limit', 200),
        ));
    }

    public function reassignContacts(Request $request, ContactPartnerTransferService $service): JsonResponse
    {
        $data = $request->validate([
            'contact_ids' => ['required', 'array', 'min:1', 'max:500'],
            'contact_ids.*' => ['required', 'string', 'max:64'],
            'responsible_partner_id' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        return $this->successResponse(
            $service->reassign($data['contact_ids'], $data['responsible_partner_id'], $data['reason']),
            'Socio responsable actualizado.',
        );
    }
}
