<?php

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Requests\ChangeStageRequest;
use App\Modules\CRM\Services\PipelineService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PipelineController
{
    use ApiResponse;


    public function stages(PipelineService $service): JsonResponse
    {
        return $this->successResponse($service->stages());
    }

    public function index(Request $request, PipelineService $service): JsonResponse
    {
        $contacts = $service->list(
            $request->query('stage'),
            (int) $request->query('limit', 100),
        );

        return $this->successResponse(array_map(fn ($contact): array => [
            'id' => $contact->getId(),
            'name' => $contact->getName(),
            'whatsapp' => $contact->getWhatsapp(),
            'lifecycle_stage' => $contact->getLifecycleStage(),
            'sales_stage' => $contact->getSalesStage(),
            'assigned_advisor_id' => $contact->getAssignedAdvisorId(),
            'metrics_summary' => $contact->getMetricsSummary(),
            'total_measurements' => $contact->getTotalMeasurements(),
        ], $contacts));
    }

    public function changeStage(
        string $id,
        ChangeStageRequest $request,
        PipelineService $service,
    ): JsonResponse {
        $idempotencyKey = (string) $request->header('X-Idempotency-Key');
        if ($idempotencyKey === '') {
            return $this->errorResponse(
                'X-Idempotency-Key es obligatorio.',
                'IDEMPOTENCY_KEY_REQUIRED',
                422,
            );
        }

        try {
            $data = $request->validated();
            $service->changeStage(
                $id,
                $data['stage'],
                $data['loss_reason'] ?? null,
                $idempotencyKey,
            );

            return $this->successResponse([], 'Etapa actualizada.');
        } catch (\Throwable $exception) {
            $status = in_array((int) $exception->getCode(), [403, 404], true)
                ? (int) $exception->getCode()
                : 422;

            return $this->errorResponse($exception->getMessage(), 'STAGE_CHANGE_FAILED', $status);
        }
    }
}
