<?php

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Contracts\TaskRepositoryInterface;
use App\Modules\CRM\Requests\CompleteTaskRequest;
use App\Modules\CRM\Requests\RescheduleTaskRequest;
use App\Modules\CRM\Requests\StoreTaskRequest;
use App\Modules\CRM\Services\TaskManagementService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TaskController
{
    use ApiResponse;

    public function index(Request $request, TaskRepositoryInterface $tasks): JsonResponse
    {
        $items = $tasks->listForCurrentUser(
            $request->query('status'),
            (int) $request->query('limit', 100),
            $request->query('contact_id') !== null ? (string) $request->query('contact_id') : null,
        );
        $contactIds = array_values(array_unique(array_map(fn ($task): string => $task->getContactId(), $items)));
        $userIds = array_values(array_unique(array_map(fn ($task): string => $task->getAssignedUserId(), $items)));
        $contactNames = DB::table('contacts')->whereIn('id', $contactIds)->pluck('name', 'id')->all();
        $userNames = DB::table('users')->whereIn('id', $userIds)->pluck('name', 'id')->all();

        return $this->successResponse(array_map(fn ($task): array => [
            'id' => $task->getId(),
            'contact_id' => $task->getContactId(),
            'contact_name' => $contactNames[$task->getContactId()] ?? null,
            'assigned_user_id' => $task->getAssignedUserId(),
            'assigned_user_name' => $userNames[$task->getAssignedUserId()] ?? null,
            'type' => $task->getType(),
            'title' => $task->getTitle(),
            'status' => $task->getStatus(),
            'due_at' => $task->getDueDate()->format(DATE_ATOM),
            'overdue' => $task->isOverdue(),
            'outcome' => $task->getOutcome(),
        ], $items));
    }

    public function store(StoreTaskRequest $request, TaskManagementService $service): JsonResponse
    {
        try {
            return $this->successResponse(
                $service->create($request->validated()),
                'Tarea creada.',
                201,
            );
        } catch (\Throwable $exception) {
            $status = in_array((int) $exception->getCode(), [403, 404, 409], true)
                ? (int) $exception->getCode()
                : 422;

            return $this->errorResponse($exception->getMessage(), 'TASK_CREATE_FAILED', $status);
        }
    }

    public function complete(
        string $id,
        CompleteTaskRequest $request,
        TaskManagementService $service,
    ): JsonResponse {
        try {
            $service->complete($id, $request->validated()['outcome']);

            return $this->successResponse([], 'Tarea completada.');
        } catch (\Throwable $exception) {
            $status = in_array((int) $exception->getCode(), [403, 404], true)
                ? (int) $exception->getCode()
                : 422;

            return $this->errorResponse($exception->getMessage(), 'TASK_UPDATE_FAILED', $status);
        }
    }

    public function reschedule(
        string $id,
        RescheduleTaskRequest $request,
        TaskManagementService $service,
    ): JsonResponse {
        try {
            $service->reschedule(
                $id,
                new \DateTimeImmutable($request->validated()['due_at']),
            );

            return $this->successResponse([], 'Tarea reagendada.');
        } catch (\Throwable $exception) {
            $status = in_array((int) $exception->getCode(), [403, 404], true)
                ? (int) $exception->getCode()
                : 422;

            return $this->errorResponse($exception->getMessage(), 'TASK_UPDATE_FAILED', $status);
        }
    }
}
