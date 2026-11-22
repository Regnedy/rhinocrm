<?php

namespace App\Modules\CRM\Repositories;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\CRM\Contracts\TaskRepositoryInterface;
use App\Modules\CRM\Domain\Entities\Task;
use App\Support\Database\GuardsTenant;
use App\Support\Database\SqlValue;
use App\Support\Database\TeamAccessScope;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class SqlTaskRepository implements TaskRepositoryInterface
{
    use GuardsTenant;

    public function __construct(private readonly UserContextDTO $context) {}

    public function find(string $id): ?Task
    {
        $row = DB::table('tasks')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }
        $this->guardTenant((string) $row->partner_id);
        if ($this->context->role === 'ADVISOR' && (string) $row->assigned_user_id !== $this->context->userId) {
            throw new \RuntimeException('Tarea no asignada al usuario.', 403);
        }
        if ($this->context->role === 'TEAM_LEADER') {
            $scope = new TeamAccessScope($this->context);
            if (!$scope->canManageUser((string) $row->assigned_user_id) && !$scope->canManageTeam($row->team_id !== null ? (string) $row->team_id : null)) {
                throw new \RuntimeException('Tarea fuera del equipo autorizado.', 403);
            }
        }
        return $this->map($row);
    }

    public function save(Task $task): void
    {
        $this->guardTenant($task->getPartnerId());
        $teamScope = new TeamAccessScope($this->context);
        if ($this->context->role === 'TEAM_LEADER' && !$teamScope->canManageUser($task->getAssignedUserId())) {
            throw new \RuntimeException('No puedes asignar tareas fuera de tu equipo.', 403);
        }
        DB::table('tasks')->updateOrInsert(['id' => $task->getId()], [
            'partner_id' => $task->getPartnerId(),
            'contact_id' => $task->getContactId(),
            'assigned_user_id' => $task->getAssignedUserId(),
            'team_id' => $teamScope->primaryTeamForUser($task->getAssignedUserId()),
            'type' => $task->getType(),
            'title' => $task->getTitle(),
            'status' => $task->getStatus(),
            'due_at' => SqlValue::timestamp($task->getDueDate()),
            'outcome' => $task->getOutcome(),
            'completed_at' => $task->getCompletedAt() ? SqlValue::timestamp($task->getCompletedAt()) : null,
            'canceled_at' => $task->getCanceledAt() ? SqlValue::timestamp($task->getCanceledAt()) : null,
            'created_at' => SqlValue::timestamp($task->getCreatedAt()),
            'updated_at' => now(),
        ]);
    }

    public function listForCurrentUser(?string $status = null, int $limit = 100, ?string $contactId = null): array
    {
        $partnerId = $this->context->partnerId ?? throw new \RuntimeException('Contexto sin partner.', 403);
        $query = DB::table('tasks')->where('partner_id', $partnerId);
        if ($this->context->role === 'ADVISOR') {
            $query->where('assigned_user_id', $this->context->userId);
        } elseif ($this->context->role === 'TEAM_LEADER') {
            (new TeamAccessScope($this->context))->applyToTasks($query);
        }
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }
        if ($contactId !== null && trim($contactId) !== '') {
            $query->where('contact_id', $contactId);
        }
        return $query->orderBy('due_at')
            ->limit(min(max($limit, 1), 200))
            ->get()
            ->map(fn (object $row): Task => $this->map($row))
            ->all();
    }

    private function map(object $row): Task
    {
        return new Task(
            (string) $row->id,
            (string) $row->partner_id,
            (string) $row->contact_id,
            (string) $row->assigned_user_id,
            (string) $row->type,
            (string) $row->title,
            (string) $row->status,
            SqlValue::date($row->due_at, new DateTimeImmutable()) ?? new DateTimeImmutable(),
            $row->outcome !== null ? (string) $row->outcome : null,
            SqlValue::date($row->created_at, new DateTimeImmutable()) ?? new DateTimeImmutable(),
            SqlValue::date($row->completed_at),
            SqlValue::date($row->canceled_at),
        );
    }
}
