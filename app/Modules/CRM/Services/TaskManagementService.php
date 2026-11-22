<?php

namespace App\Modules\CRM\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Modules\Partners\Services\PartnerWorkModeService;
use App\Support\Database\SqlValue;
use App\Support\Database\TeamAccessScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TaskManagementService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly PartnerWorkModeService $workMode,
    ) {}

    public function create(array $data): array
    {
        $partnerId = $this->context->partnerId ?? throw new \RuntimeException('Contexto sin socio.', 403);
        $contact = DB::table('contacts')
            ->where('id', (string) $data['contact_id'])
            ->whereNull('deleted_at')
            ->first();

        if ($contact === null) {
            throw new \RuntimeException('Contacto no encontrado.', 404);
        }
        if ((string) $contact->partner_id !== $partnerId) {
            throw new TenantViolationException($this->context->userId, (string) $contact->partner_id);
        }
        if ((string) $contact->status !== 'ACTIVE') {
            throw new \RuntimeException('No se pueden crear tareas para un cliente bloqueado o inactivo.', 422);
        }

        $teamScope = new TeamAccessScope($this->context);
        if ($this->context->role === 'ADVISOR' && (string) ($contact->assigned_advisor_id ?? '') !== $this->context->userId) {
            throw new \RuntimeException('No tienes acceso a este contacto.', 403);
        }
        if (!$teamScope->canAccessContact($contact)) {
            throw new \RuntimeException('Contacto fuera del equipo autorizado.', 403);
        }

        if ($this->workMode->isTeamMode()) {
            $assignedUserId = (string) ($data['assigned_user_id'] ?? '');
            if ($assignedUserId === '') {
                $assignedUserId = $this->context->role === 'ADVISOR'
                    ? $this->context->userId
                    : (string) ($contact->assigned_advisor_id ?? $this->context->userId);
            }
        } else {
            $assignedUserId = $this->context->userId;
        }
        if ($this->context->role === 'ADVISOR' && $assignedUserId !== $this->context->userId) {
            throw new \RuntimeException('Un asesor solo puede asignarse tareas a sí mismo.', 403);
        }
        if (!$teamScope->canManageUser($assignedUserId)) {
            throw new \RuntimeException('No puedes asignar tareas fuera de tu equipo.', 403);
        }

        $assignee = DB::table('users')
            ->where('id', $assignedUserId)
            ->where('partner_id', $partnerId)
            ->where('status', 'ACTIVE')
            ->whereNull('deleted_at')
            ->first();
        if ($assignee === null) {
            throw new \RuntimeException('Usuario asignado inválido o suspendido.', 422);
        }

        $campaignId = isset($data['campaign_id']) && trim((string) $data['campaign_id']) !== '' ? trim((string) $data['campaign_id']) : null;
        if ($campaignId !== null) {
            $campaign = DB::table('campaigns')->where('id', $campaignId)->where('partner_id', $partnerId)->whereNull('deleted_at')->first();
            if ($campaign === null || in_array((string) $campaign->status, ['CLOSED', 'CANCELLED'], true)) {
                throw new \RuntimeException('Evento inválido o cerrado.', 422);
            }
        }

        $taskId = 'TSK_'.strtoupper((string) Str::ulid());
        $teamId = $this->workMode->isTeamMode()
            ? ($teamScope->primaryTeamForUser($assignedUserId)
                ?? ($contact->origin_team_id !== null ? (string) $contact->origin_team_id : null))
            : null;
        $dueAt = new \DateTimeImmutable((string) $data['due_at']);

        DB::transaction(function () use ($taskId, $partnerId, $contact, $assignedUserId, $teamId, $campaignId, $data, $dueAt): void {
            DB::table('tasks')->insert([
                'id' => $taskId,
                'partner_id' => $partnerId,
                'contact_id' => (string) $contact->id,
                'assigned_user_id' => $assignedUserId,
                'team_id' => $teamId,
                'campaign_id' => $campaignId,
                'type' => strtoupper(trim((string) $data['type'])),
                'title' => trim((string) $data['title']),
                'status' => 'PENDING',
                'due_at' => $dueAt->format('Y-m-d H:i:s'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('timeline_activities')->insert([
                'id' => 'TML_'.$taskId,
                'partner_id' => $partnerId,
                'contact_id' => (string) $contact->id,
                'type' => 'TASK_CREATED',
                'description' => 'Tarea creada: '.trim((string) $data['title']),
                'metadata' => SqlValue::encode([
                    'task_id' => $taskId,
                    'assigned_user_id' => $assignedUserId,
                    'team_id' => $teamId,
                    'campaign_id' => $campaignId,
                    'due_at' => $dueAt->format(DATE_ATOM),
                ]),
                'actor_id' => $this->context->userId,
                'created_at' => now(),
            ]);
        }, 3);

        return [
            'id' => $taskId,
            'contact_id' => (string) $contact->id,
            'assigned_user_id' => $assignedUserId,
            'team_id' => $teamId,
            'type' => strtoupper(trim((string) $data['type'])),
            'title' => trim((string) $data['title']),
            'status' => 'PENDING',
            'due_at' => $dueAt->format(DATE_ATOM),
        ];
    }

    public function complete(string $taskId, string $outcome): void
    {
        $this->mutate($taskId, 'COMPLETED', $outcome, null);
    }

    public function reschedule(string $taskId, \DateTimeImmutable $due): void
    {
        $this->mutate($taskId, 'PENDING', 'Rescheduled', $due);
    }

    private function mutate(string $taskId, string $status, string $outcome, ?\DateTimeImmutable $due): void
    {
        DB::transaction(function () use ($taskId, $status, $outcome, $due): void {
            $task = DB::table('tasks')->where('id', $taskId)->lockForUpdate()->first();
            if ($task === null) {
                throw new \RuntimeException('Tarea no encontrada.', 404);
            }
            $partnerId = (string) $task->partner_id;
            if (!$this->context->isSuperAdmin() && $this->context->partnerId !== $partnerId) {
                throw new TenantViolationException($this->context->userId, $partnerId);
            }
            if ($this->context->role === 'ADVISOR' && (string) $task->assigned_user_id !== $this->context->userId) {
                throw new \RuntimeException('Tarea no asignada al usuario.', 403);
            }
            if ($this->context->role === 'TEAM_LEADER') {
                $scope = new TeamAccessScope($this->context);
                $teamId = $task->team_id !== null ? (string) $task->team_id : null;
                if (!$scope->canManageUser((string) $task->assigned_user_id) && !$scope->canManageTeam($teamId)) {
                    throw new \RuntimeException('Tarea fuera del equipo autorizado.', 403);
                }
            }
            if ((string) $task->status !== 'PENDING') {
                throw new \DomainException('La tarea ya no está pendiente.');
            }
            $updates = ['status' => $status, 'outcome' => $outcome, 'updated_at' => now()];
            if ($status === 'COMPLETED') {
                $updates['completed_at'] = now();
            }
            if ($due !== null) {
                $updates['due_at'] = $due->format('Y-m-d H:i:s');
            }
            DB::table('tasks')->where('id', $taskId)->update($updates);
            DB::table('timeline_activities')->insert([
                'id' => 'TML_TASK_'.hash('sha256', $taskId.'|'.$status.'|'.microtime(true)),
                'partner_id' => $partnerId,
                'contact_id' => $task->contact_id,
                'type' => $status === 'COMPLETED' ? 'TASK_COMPLETED' : 'TASK_RESCHEDULED',
                'description' => $status === 'COMPLETED' ? 'Tarea completada.' : 'Tarea reagendada.',
                'metadata' => SqlValue::encode(['task_id' => $taskId, 'outcome' => $outcome]),
                'actor_id' => $this->context->userId,
                'created_at' => now(),
            ]);
        }, 3);
    }
}
