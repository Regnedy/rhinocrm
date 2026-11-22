<?php

namespace App\Modules\NutritionCampaigns\Controllers;

use App\Modules\NutritionCampaigns\Services\NutritionCampaignService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NutritionCampaignController
{
    use ApiResponse;

    public function index(Request $request, NutritionCampaignService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->list($request->only([
            'status', 'search', 'from', 'to',
        ])));
    }

    public function store(Request $request, NutritionCampaignService $service): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'linked_event_id' => ['nullable', 'string', 'max:64'],
            'team_id' => ['nullable', 'string', 'max:64'],
            'responsible_user_id' => ['nullable', 'string', 'max:128'],
            'scheduled_at' => ['nullable', 'date'],
            'whatsapp_template_name' => ['nullable', 'string', 'max:180'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'source_filters' => ['nullable', 'array'],
        ]);

        return $this->respond(
            fn (): array => $service->create($data),
            'Campaña de seguimiento creada.',
            201,
        );
    }

    public function show(string $id, NutritionCampaignService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->show($id));
    }

    public function update(string $id, Request $request, NutritionCampaignService $service): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'linked_event_id' => ['nullable', 'string', 'max:64'],
            'team_id' => ['nullable', 'string', 'max:64'],
            'responsible_user_id' => ['nullable', 'string', 'max:128'],
            'scheduled_at' => ['nullable', 'date'],
            'whatsapp_template_name' => ['nullable', 'string', 'max:180'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'source_filters' => ['nullable', 'array'],
        ]);

        return $this->respond(
            fn (): array => $service->update($id, $data),
            'Campaña actualizada.',
        );
    }

    public function searchCandidates(Request $request, NutritionCampaignService $service): JsonResponse
    {
        $data = $request->validate([
            'campaign_id' => ['nullable', 'string', 'max:80'],
            'search' => ['nullable', 'string', 'max:180'],
            'plan_from' => ['nullable', 'date'],
            'plan_to' => ['nullable', 'date'],
            'min_days_since_plan' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'max_days_since_plan' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'delivered_only' => ['nullable', 'boolean'],
            'no_measurement_after_plan' => ['nullable', 'boolean'],
            'require_marketing_consent' => ['nullable', 'boolean'],
            'lifecycle_stage' => ['nullable', 'in:PROSPECT,CUSTOMER'],
            'assigned_user_id' => ['nullable', 'string', 'max:128'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        return $this->respond(fn (): array => $service->candidates($data));
    }

    public function addMembers(string $id, Request $request, NutritionCampaignService $service): JsonResponse
    {
        $data = $request->validate([
            'contact_ids' => ['required', 'array', 'min:1', 'max:500'],
            'contact_ids.*' => ['required', 'string', 'max:64'],
        ]);

        return $this->respond(
            fn (): array => $service->addMembers($id, $data['contact_ids']),
            'Personas agregadas a la campaña.',
        );
    }

    public function removeMember(
        string $id,
        string $memberId,
        NutritionCampaignService $service,
    ): JsonResponse {
        return $this->respond(
            fn (): array => $service->removeMember($id, $memberId),
            'Persona retirada de la campaña.',
        );
    }

    public function activate(string $id, NutritionCampaignService $service): JsonResponse
    {
        return $this->respond(
            fn (): array => $service->activate($id),
            'Campaña activada.',
        );
    }

    public function close(string $id, NutritionCampaignService $service): JsonResponse
    {
        return $this->respond(
            fn (): array => $service->close($id),
            'Campaña cerrada.',
        );
    }

    public function dispatchInvitations(
        string $id,
        Request $request,
        NutritionCampaignService $service,
    ): JsonResponse {
        $data = $request->validate([
            'member_ids' => ['required', 'array', 'min:1', 'max:500'],
            'member_ids.*' => ['required', 'string', 'max:100'],
        ]);

        return $this->respond(
            fn (): array => $service->dispatchInvitations($id, $data['member_ids']),
            'Invitaciones programadas individualmente.',
            202,
        );
    }

    public function changeStage(
        string $id,
        string $memberId,
        Request $request,
        NutritionCampaignService $service,
    ): JsonResponse {
        $data = $request->validate([
            'stage' => ['required', 'string', 'max:48'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->respond(
            fn (): array => $service->changeStage(
                $id,
                $memberId,
                $data['stage'],
                $data['note'] ?? null,
            ),
            'Etapa actualizada.',
        );
    }

    public function report(string $id, NutritionCampaignService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->report($id));
    }

    private function respond(
        callable $callback,
        string $message = 'Success',
        int $status = 200,
    ): JsonResponse {
        try {
            return $this->successResponse($callback(), $message, $status);
        } catch (\Throwable $exception) {
            $code = (int) $exception->getCode();
            $statusCode = in_array($code, [403, 404, 409, 422], true) ? $code : 500;
            if ($statusCode === 500) {
                report($exception);
            }

            return $this->errorResponse(
                $statusCode === 500
                    ? 'No fue posible completar la operación de seguimiento nutricional.'
                    : $exception->getMessage(),
                'NUTRITION_CAMPAIGN_OPERATION_FAILED',
                $statusCode,
            );
        }
    }
}
