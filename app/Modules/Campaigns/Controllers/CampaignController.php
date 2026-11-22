<?php

namespace App\Modules\Campaigns\Controllers;

use App\Modules\Campaigns\Requests\RegisterCampaignParticipantRequest;
use App\Modules\Campaigns\Requests\StoreCampaignRequest;
use App\Modules\Campaigns\Requests\UpdateCampaignRequest;
use App\Modules\Campaigns\Services\CampaignService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CampaignController
{
    use ApiResponse;

    public function index(Request $request, CampaignService $service): JsonResponse
    {
        return $this->successResponse($service->list($request->only(['status', 'from', 'to', 'search'])));
    }

    public function store(StoreCampaignRequest $request, CampaignService $service): JsonResponse
    {
        return $this->respond(fn () => $service->create($request->validated()), 'Evento creado.', 201);
    }

    public function show(string $id, CampaignService $service): JsonResponse
    {
        return $this->respond(fn () => $service->show($id));
    }

    public function update(string $id, UpdateCampaignRequest $request, CampaignService $service): JsonResponse
    {
        return $this->respond(fn () => $service->update($id, $request->validated()), 'Evento actualizado.');
    }

    public function activate(string $id, CampaignService $service): JsonResponse
    {
        return $this->respond(fn () => $service->activate($id), 'Evento activado.');
    }

    public function close(string $id, CampaignService $service): JsonResponse
    {
        return $this->respond(fn () => $service->close($id), 'Evento cerrado.');
    }

    public function cancel(string $id, CampaignService $service): JsonResponse
    {
        return $this->respond(fn () => $service->cancel($id), 'Evento cancelado.');
    }

    public function participants(string $id, Request $request, CampaignService $service): JsonResponse
    {
        $filters = $request->only(['filter', 'commercial_status', 'limit', 'cursor']);
        $filters['_paged'] = $request->hasAny(['filter', 'commercial_status', 'limit', 'cursor']);
        return $this->respond(fn () => $service->participants($id, $filters));
    }

    public function pipeline(string $id, CampaignService $service): JsonResponse
    {
        return $this->respond(fn () => $service->pipeline($id));
    }

    public function registerParticipant(string $id, RegisterCampaignParticipantRequest $request, CampaignService $service): JsonResponse
    {
        return $this->respond(fn () => $service->registerParticipant($id, $request->validated()), 'Participante registrado.', 201);
    }

    public function report(string $id, CampaignService $service): JsonResponse
    {
        return $this->respond(fn () => $service->report($id));
    }

    private function respond(callable $callback, string $message = 'Success', int $status = 200): JsonResponse
    {
        try {
            return $this->successResponse($callback(), $message, $status);
        } catch (\Throwable $exception) {
            $code = (int) $exception->getCode();
            $statusCode = in_array($code, [403, 404, 409, 422], true) ? $code : 500;
            if ($statusCode === 500) report($exception);
            return $this->errorResponse(
                $statusCode === 500 ? 'No fue posible completar la operación del evento.' : $exception->getMessage(),
                'CAMPAIGN_OPERATION_FAILED',
                $statusCode,
            );
        }
    }
}
