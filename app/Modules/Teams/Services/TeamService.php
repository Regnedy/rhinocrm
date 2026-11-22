<?php

namespace App\Modules\Teams\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TeamService
{
    public function __construct(private readonly UserContextDTO $context) {}

    public function list(): array
    {
        $partnerId = $this->partnerId();
        $query = DB::table('teams as t')->where('t.partner_id', $partnerId)->whereNull('t.deleted_at');
        if (in_array($this->context->role, ['TEAM_LEADER', 'ADVISOR'], true)) {
            $teamIds = DB::table('team_members')->where('partner_id', $partnerId)->where('user_id', $this->context->userId)->where('status', 'ACTIVE')->pluck('team_id');
            $query->where(function ($q) use ($teamIds): void {
                $q->where('t.leader_user_id', $this->context->userId)->orWhereIn('t.id', $teamIds);
            });
        }
        return $query->leftJoin('users as leader', 'leader.id', '=', 't.leader_user_id')
            ->select('t.*', 'leader.name as leader_name')
            ->orderBy('t.name')->get()->map(fn (object $row): array => $this->teamArray($row))->all();
    }

    public function create(array $data): array
    {
        $partnerId = $this->partnerId();
        $this->assertManager();
        $data['location_id'] = $data['location_id'] ?? DB::table('locations')->where('partner_id', $partnerId)->where('status', 'ACTIVE')->whereNull('deleted_at')->orderBy('created_at')->value('id');
        $this->validateLocation($data['location_id'] ?? null, $partnerId);
        $this->validateUser($data['leader_user_id'] ?? null, $partnerId);
        $id = 'TEM_'.strtoupper((string) Str::ulid());
        DB::transaction(function () use ($id, $partnerId, $data): void {
            DB::table('teams')->insert([
                'id' => $id, 'partner_id' => $partnerId, 'location_id' => $data['location_id'] ?? null,
                'name' => trim((string) $data['name']), 'leader_user_id' => $data['leader_user_id'] ?? null,
                'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
            ]);
            if (!empty($data['leader_user_id'])) {
                $this->assignMember($id, (string) $data['leader_user_id'], 'LEADER', $partnerId);
            }
        }, 3);
        return $this->show($id);
    }

    public function show(string $teamId): array
    {
        $team = $this->authorizedTeam($teamId);
        $result = $this->teamArray($team);
        $result['members'] = DB::table('team_members as tm')->join('users as u', 'u.id', '=', 'tm.user_id')
            ->where('tm.team_id', $teamId)->where('tm.status', 'ACTIVE')
            ->select('u.id', 'u.name', 'u.email', 'u.role', 'u.status', 'tm.member_role', 'tm.joined_at')
            ->orderBy('u.name')->get()->all();
        return $result;
    }

    public function update(string $teamId, array $data): array
    {
        $this->assertManagerOrLeader($teamId);
        $team = $this->authorizedTeam($teamId);
        $partnerId = (string) $team->partner_id;
        if (array_key_exists('location_id', $data)) {
            $this->validateLocation($data['location_id'], $partnerId);
        }
        if (array_key_exists('leader_user_id', $data)) {
            $this->validateUser($data['leader_user_id'], $partnerId);
        }
        DB::transaction(function () use ($teamId, $partnerId, $data): void {
            $update = array_intersect_key($data, array_flip(['name', 'location_id', 'leader_user_id', 'status']));
            $update['updated_at'] = now();
            DB::table('teams')->where('id', $teamId)->update($update);
            if (array_key_exists('leader_user_id', $data) && $data['leader_user_id'] !== null) {
                DB::table('team_members')->where('team_id', $teamId)->where('member_role', 'LEADER')->update(['member_role' => 'MEMBER', 'updated_at' => now()]);
                $this->assignMember($teamId, (string) $data['leader_user_id'], 'LEADER', $partnerId);
            }
        }, 3);
        return $this->show($teamId);
    }

    public function addMember(string $teamId, string $userId, string $memberRole = 'MEMBER'): array
    {
        $this->assertManagerOrLeader($teamId);
        $team = $this->authorizedTeam($teamId);
        $this->validateUser($userId, (string) $team->partner_id);
        DB::transaction(fn () => $this->assignMember($teamId, $userId, strtoupper($memberRole), (string) $team->partner_id), 3);
        return $this->show($teamId);
    }

    public function removeMember(string $teamId, string $userId): array
    {
        $this->assertManagerOrLeader($teamId);
        $team = $this->authorizedTeam($teamId);
        DB::table('team_members')->where('team_id', $teamId)->where('user_id', $userId)->update([
            'status' => 'INACTIVE', 'left_at' => now(), 'updated_at' => now(),
        ]);
        if ((string) ($team->leader_user_id ?? '') === $userId) {
            DB::table('teams')->where('id', $teamId)->update(['leader_user_id' => null, 'updated_at' => now()]);
        }
        return $this->show($teamId);
    }

    public function setGoal(string $teamId, array $data): array
    {
        $this->assertManagerOrLeader($teamId);
        $team = $this->authorizedTeam($teamId);
        $userId = $data['user_id'] ?? null;
        if ($userId !== null) {
            $this->validateUser((string) $userId, (string) $team->partner_id);
        }
        $goalId = 'GOL_'.strtoupper(substr(hash('sha256', $team->partner_id.'|'.$teamId.'|'.($userId ?? 'TEAM').'|'.$data['period']), 0, 24));
        DB::table('team_goals')->updateOrInsert(['id' => $goalId], [
            'partner_id' => $team->partner_id,
            'team_id' => $teamId,
            'user_id' => $userId,
            'period' => $data['period'],
            'prospects_target' => (int) ($data['prospects_target'] ?? 0),
            'measurements_target' => (int) ($data['measurements_target'] ?? 0),
            'sales_target' => (int) ($data['sales_target'] ?? 0),
            'revenue_target_minor' => (int) ($data['revenue_target_minor'] ?? 0),
            'currency' => strtoupper((string) ($data['currency'] ?? 'MXN')),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return (array) DB::table('team_goals')->where('id', $goalId)->first();
    }

    public function report(string $teamId, string $period): array
    {
        $team = $this->authorizedTeam($teamId);
        [$start, $end] = $this->periodRange($period);
        $membersQuery = DB::table('team_members as tm')->join('users as u', 'u.id', '=', 'tm.user_id')
            ->where('tm.team_id', $teamId)->where('tm.status', 'ACTIVE');
        if ($this->context->role === 'ADVISOR') {
            $membersQuery->where('u.id', $this->context->userId);
        }
        $members = $membersQuery->select('u.id', 'u.name', 'u.role', 'u.status')->orderBy('u.name')->get();

        $rows = [];
        foreach ($members as $member) {
            $metrics = $this->memberMetrics((string) $team->partner_id, $teamId, (string) $member->id, $start, $end);
            $goal = DB::table('team_goals')->where('team_id', $teamId)->where('user_id', $member->id)->where('period', $period)->first();
            $rows[] = array_merge((array) $member, $metrics, ['goal' => $goal, 'goal_progress' => $this->goalProgress($metrics, $goal)]);
        }
        $totals = [
            'prospects_generated' => array_sum(array_column($rows, 'prospects_generated')),
            'prospects_assigned' => array_sum(array_column($rows, 'prospects_assigned')),
            'measurements' => array_sum(array_column($rows, 'measurements')),
            'sales_closed' => array_sum(array_column($rows, 'sales_closed')),
            'revenue_generated_minor' => array_sum(array_column($rows, 'revenue_generated_minor')),
            'tasks_completed' => array_sum(array_column($rows, 'tasks_completed')),
            'tasks_overdue' => array_sum(array_column($rows, 'tasks_overdue')),
        ];
        $teamGoal = DB::table('team_goals')->where('team_id', $teamId)->whereNull('user_id')->where('period', $period)->first();
        return [
            'period' => $period,
            'team' => $this->teamArray($team),
            'totals' => $totals,
            'goal' => $teamGoal,
            'goal_progress' => $this->goalProgress($totals, $teamGoal),
            'members' => $rows,
        ];
    }

    public function summary(string $period): array
    {
        return array_map(fn (array $team): array => $this->report((string) $team['id'], $period), $this->list());
    }

    private function memberMetrics(string $partnerId, string $teamId, string $userId, string $start, string $end): array
    {
        $sales = DB::table('sales')->where('partner_id', $partnerId)->where('team_id', $teamId)->where('advisor_id', $userId)
            ->whereBetween('closed_at', [$start, $end])->selectRaw("COUNT(CASE WHEN status='COMPLETED' THEN 1 END) sales_closed")
            ->selectRaw("COALESCE(SUM(CASE WHEN status='COMPLETED' THEN total_revenue_minor ELSE 0 END),0) revenue")->first();
        return [
            'prospects_generated' => DB::table('contacts')->where('partner_id', $partnerId)->where('origin_team_id', $teamId)->where('generated_by_user_id', $userId)->whereBetween('created_at', [$start, $end])->count(),
            'prospects_assigned' => DB::table('contacts')->where('partner_id', $partnerId)->where('assigned_advisor_id', $userId)->whereBetween('created_at', [$start, $end])->count(),
            'measurements' => DB::table('measurements as m')->join('contacts as c', 'c.id', '=', 'm.contact_id')->where('m.partner_id', $partnerId)->where('c.assigned_advisor_id', $userId)->whereBetween('m.timestamp_local', [$start, $end])->count(),
            'sales_closed' => (int) ($sales->sales_closed ?? 0),
            'revenue_generated_minor' => (int) ($sales->revenue ?? 0),
            'tasks_completed' => DB::table('tasks')->where('partner_id', $partnerId)->where('assigned_user_id', $userId)->where('status', 'COMPLETED')->whereBetween('completed_at', [$start, $end])->count(),
            'tasks_overdue' => DB::table('tasks')->where('partner_id', $partnerId)->where('assigned_user_id', $userId)->where('status', 'PENDING')->where('due_at', '<', now())->count(),
        ];
    }

    private function goalProgress(array $metrics, ?object $goal): array
    {
        if ($goal === null) return [];
        return [
            'prospects_percent' => $this->percent((int) ($metrics['prospects_generated'] ?? 0), (int) $goal->prospects_target),
            'measurements_percent' => $this->percent((int) ($metrics['measurements'] ?? 0), (int) $goal->measurements_target),
            'sales_percent' => $this->percent((int) ($metrics['sales_closed'] ?? 0), (int) $goal->sales_target),
            'revenue_percent' => $this->percent((int) ($metrics['revenue_generated_minor'] ?? 0), (int) $goal->revenue_target_minor),
        ];
    }

    private function percent(int $actual, int $target): ?float { return $target > 0 ? round(($actual / $target) * 100, 2) : null; }
    private function partnerId(): string { return $this->context->partnerId ?? throw new \RuntimeException('Contexto sin socio.', 403); }

    private function authorizedTeam(string $teamId): object
    {
        $team = DB::table('teams')->where('id', $teamId)->whereNull('deleted_at')->first();
        if ($team === null) throw new \RuntimeException('Equipo no encontrado.', 404);
        if ((string) $team->partner_id !== $this->partnerId()) throw new TenantViolationException($this->context->userId, (string) $team->partner_id);
        if (in_array($this->context->role, ['TEAM_LEADER', 'ADVISOR'], true)) {
            $member = DB::table('team_members')->where('team_id', $teamId)->where('user_id', $this->context->userId)->where('status', 'ACTIVE')->exists();
            if (!$member && (string) ($team->leader_user_id ?? '') !== $this->context->userId) throw new \RuntimeException('No puedes acceder a este equipo.', 403);
        }
        return $team;
    }

    private function assertManager(): void
    {
        if (!in_array($this->context->role, ['PARTNER_ADMIN', 'LOCATION_MANAGER'], true)) throw new \RuntimeException('No puedes crear equipos.', 403);
    }
    private function assertManagerOrLeader(string $teamId): void
    {
        if (in_array($this->context->role, ['PARTNER_ADMIN', 'LOCATION_MANAGER'], true)) return;
        $team = $this->authorizedTeam($teamId);
        if ($this->context->role !== 'TEAM_LEADER' || (string) ($team->leader_user_id ?? '') !== $this->context->userId) throw new \RuntimeException('No puedes modificar este equipo.', 403);
    }

    private function validateLocation(?string $locationId, string $partnerId): void
    {
        if ($locationId === null || $locationId === '') return;
        $valid = DB::table('locations')->where('id', $locationId)->where('partner_id', $partnerId)->where('status', 'ACTIVE')->whereNull('deleted_at')->exists();
        if (!$valid) throw new \RuntimeException('Ubicación inválida.', 422);
    }
    private function validateUser(?string $userId, string $partnerId): void
    {
        if ($userId === null || $userId === '') return;
        $valid = DB::table('users')->where('id', $userId)->where('partner_id', $partnerId)->where('status', 'ACTIVE')->whereNull('deleted_at')->exists();
        if (!$valid) throw new \RuntimeException('Usuario inválido para el equipo.', 422);
    }

    private function assignMember(string $teamId, string $userId, string $role, string $partnerId): void
    {
        if ($role === 'LEADER') DB::table('teams')->where('id', $teamId)->update(['leader_user_id' => $userId, 'updated_at' => now()]);
        DB::table('team_members')->updateOrInsert(['team_id' => $teamId, 'user_id' => $userId], [
            'partner_id' => $partnerId, 'member_role' => $role, 'status' => 'ACTIVE',
            'joined_at' => now(), 'left_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function teamArray(object $row): array
    {
        return [
            'id' => $row->id, 'partner_id' => $row->partner_id, 'location_id' => $row->location_id,
            'name' => $row->name, 'leader_user_id' => $row->leader_user_id,
            'leader_name' => $row->leader_name ?? null, 'status' => $row->status,
            'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
        ];
    }

    /** @return array{0:string,1:string} */
    private function periodRange(string $period): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) throw new \InvalidArgumentException('Periodo inválido.');
        $start = \Carbon\CarbonImmutable::createFromFormat('Y-m', $period)->startOfMonth();
        return [$start->toDateTimeString(), $start->endOfMonth()->endOfDay()->toDateTimeString()];
    }
}
