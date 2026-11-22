<?php

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Requests\StoreContactRequest;
use App\Modules\CRM\Requests\UpdateContactRequest;
use App\Modules\CRM\Services\ContactManagementService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContactController
{
    use ApiResponse;

    public function index(Request $request, ContactManagementService $service): JsonResponse
    {
        return $this->successResponse($service->list($request->only(['limit','cursor','search','status','stage','lifecycle','advisor_id','team_id','campaign_id'])));
    }
    public function store(StoreContactRequest $request, ContactManagementService $service): JsonResponse
    {
        return $this->successResponse($service->create($request->validated()), 'Contacto creado.', 201);
    }
    public function show(string $id, ContactManagementService $service): JsonResponse { return $this->successResponse($service->show($id)); }
    public function update(string $id, UpdateContactRequest $request, ContactManagementService $service): JsonResponse
    {
        return $this->successResponse($service->update($id, $request->validated()), 'Contacto actualizado.');
    }
    public function measurements(string $id, Request $request, ContactManagementService $service): JsonResponse
    {
        return $this->successResponse($service->measurements($id, (int) $request->query('limit', 100)));
    }

    public function measurementDashboard(string $id, Request $request, ContactManagementService $service): JsonResponse
    {
        return $this->successResponse($service->measurementDashboard($id, (int) $request->query('limit', 30)));
    }
}
