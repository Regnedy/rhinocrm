<?php

namespace App\Modules\Workflows\Controllers;

use App\Modules\Workflows\Contracts\WorkflowRepositoryInterface;
use App\Modules\Workflows\Requests\StoreWorkflowRequest;
use App\Modules\Workflows\Services\WorkflowDefinitionService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkflowController
{
    use ApiResponse;

    public function index(
        Request $request,
        WorkflowRepositoryInterface $workflows,
    ): JsonResponse {
        $items = $workflows->list((int) $request->query('limit', 100));

        return $this->successResponse(array_map(fn ($workflow): array => [
            'id' => $workflow->getId(),
            'name' => $workflow->getName(),
            'version' => $workflow->getVersion(),
            'trigger_event' => $workflow->getTriggerEvent(),
            'entry_node_id' => $workflow->getEntryNodeId(),
            'is_active' => $workflow->isActive(),
        ], $items));
    }

    public function store(
        StoreWorkflowRequest $request,
        WorkflowDefinitionService $service,
    ): JsonResponse {
        try {
            $workflow = $service->create($request->validated());

            return $this->successResponse([
                'id' => $workflow->getId(),
                'version' => $workflow->getVersion(),
            ], 'Workflow creado.', 201);
        } catch (\Throwable $exception) {
            return $this->errorResponse($exception->getMessage(), 'WORKFLOW_CREATE_FAILED', 422);
        }
    }
}
