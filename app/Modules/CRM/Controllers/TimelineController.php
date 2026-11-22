<?php

namespace App\Modules\CRM\Controllers;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\CRM\Contracts\TimelineActivityRepositoryInterface;
use App\Modules\CRM\Domain\Entities\TimelineActivity;
use App\Modules\CRM\Requests\StoreTimelineNoteRequest;
use App\Modules\CRM\Services\ContactManagementService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class TimelineController
{
    use ApiResponse;

    public function index(
        string $contactId,
        Request $request,
        TimelineActivityRepositoryInterface $repository,
    ): JsonResponse {
        $activities = $repository->listForContact(
            $contactId,
            (int) $request->query('limit', 100),
        );

        return $this->successResponse(array_map(fn ($activity): array => [
            'id' => $activity->id,
            'type' => $activity->type,
            'description' => $activity->description,
            'metadata' => $activity->metadata,
            'actor_id' => $activity->actorId,
            'created_at' => $activity->createdAt->format(DATE_ATOM),
        ], $activities));
    }

    public function store(
        string $contactId,
        StoreTimelineNoteRequest $request,
        TimelineActivityRepositoryInterface $repository,
        ContactManagementService $contacts,
        UserContextDTO $context,
    ): JsonResponse {
        // show realiza las mismas comprobaciones de socio, asesor y equipo.
        $contacts->show($contactId);
        $partnerId = $context->partnerId ?? throw new \RuntimeException('Contexto sin socio.', 403);
        $activity = new TimelineActivity(
            id: 'TML_NOTE_'.strtoupper((string) Str::ulid()),
            partnerId: $partnerId,
            contactId: $contactId,
            type: 'NOTE',
            description: trim((string) $request->validated('note')),
            metadata: ['source' => 'CRM_WEB'],
            actorId: $context->userId,
        );
        $repository->save($activity);

        return $this->successResponse([
            'id' => $activity->id,
            'type' => $activity->type,
            'description' => $activity->description,
            'created_at' => $activity->createdAt->format(DATE_ATOM),
        ], 'Nota guardada.', 201);
    }
}
