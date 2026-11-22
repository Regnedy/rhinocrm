<?php

namespace App\Support\Database;

use App\Modules\Auth\DTOs\UserContextDTO;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Applies team-level visibility for TEAM_LEADER users.
 *
 * Partner administrators and location managers are intentionally not scoped
 * here; their permissions are enforced by the tenant and authorization layer.
 */
final class TeamAccessScope
{
    private ?array $teamIds = null;
    private ?array $memberUserIds = null;

    public function __construct(private readonly UserContextDTO $context) {}

    public function isRestricted(): bool
    {
        return $this->context->role === 'TEAM_LEADER';
    }

    /** @return list<string> */
    public function teamIds(): array
    {
        if ($this->teamIds !== null) {
            return $this->teamIds;
        }

        if (!$this->isRestricted() || $this->context->partnerId === null) {
            return $this->teamIds = [];
        }

        $led = DB::table('teams')
            ->where('partner_id', $this->context->partnerId)
            ->where('status', 'ACTIVE')
            ->whereNull('deleted_at')
            ->where('leader_user_id', $this->context->userId)
            ->pluck('id');

        $member = DB::table('team_members')
            ->where('partner_id', $this->context->partnerId)
            ->where('user_id', $this->context->userId)
            ->where('status', 'ACTIVE')
            ->pluck('team_id');

        return $this->teamIds = $led->merge($member)
            ->filter()
            ->unique()
            ->values()
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    /** @return list<string> */
    public function memberUserIds(): array
    {
        if ($this->memberUserIds !== null) {
            return $this->memberUserIds;
        }

        $teamIds = $this->teamIds();
        if ($teamIds === []) {
            return $this->memberUserIds = [];
        }

        $members = DB::table('team_members')
            ->where('partner_id', $this->context->partnerId)
            ->whereIn('team_id', $teamIds)
            ->where('status', 'ACTIVE')
            ->pluck('user_id');

        $leaders = DB::table('teams')
            ->where('partner_id', $this->context->partnerId)
            ->whereIn('id', $teamIds)
            ->where('status', 'ACTIVE')
            ->whereNull('deleted_at')
            ->whereNotNull('leader_user_id')
            ->pluck('leader_user_id');

        return $this->memberUserIds = $members->merge($leaders)
            ->push($this->context->userId)
            ->filter()
            ->unique()
            ->values()
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    public function applyToContacts(Builder $query): Builder
    {
        if (!$this->isRestricted()) {
            return $query;
        }

        $teamIds = $this->teamIds();
        $userIds = $this->memberUserIds();
        if ($teamIds === [] && $userIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $scope) use ($teamIds, $userIds): void {
            if ($teamIds !== []) {
                $scope->whereIn('origin_team_id', $teamIds);
            }
            if ($userIds !== []) {
                $method = $teamIds === [] ? 'whereIn' : 'orWhereIn';
                $scope->{$method}('assigned_advisor_id', $userIds);
            }
        });
    }

    public function applyToTasks(Builder $query): Builder
    {
        if (!$this->isRestricted()) {
            return $query;
        }

        $teamIds = $this->teamIds();
        $userIds = $this->memberUserIds();
        if ($teamIds === [] && $userIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $scope) use ($teamIds, $userIds): void {
            if ($teamIds !== []) {
                $scope->whereIn('team_id', $teamIds);
            }
            if ($userIds !== []) {
                $method = $teamIds === [] ? 'whereIn' : 'orWhereIn';
                $scope->{$method}('assigned_user_id', $userIds);
            }
        });
    }

    public function canAccessContact(object $contact): bool
    {
        if (!$this->isRestricted()) {
            return true;
        }

        return ($contact->origin_team_id !== null && in_array((string) $contact->origin_team_id, $this->teamIds(), true))
            || ($contact->assigned_advisor_id !== null && in_array((string) $contact->assigned_advisor_id, $this->memberUserIds(), true));
    }

    public function canManageTeam(?string $teamId): bool
    {
        return !$this->isRestricted()
            || ($teamId !== null && in_array($teamId, $this->teamIds(), true));
    }

    public function canManageUser(?string $userId): bool
    {
        return !$this->isRestricted()
            || ($userId !== null && in_array($userId, $this->memberUserIds(), true));
    }

    public function primaryTeamForUser(string $userId): ?string
    {
        if ($this->isRestricted() && !$this->canManageUser($userId)) {
            return null;
        }

        $teamId = DB::table('team_members')
            ->where('partner_id', $this->context->partnerId)
            ->where('user_id', $userId)
            ->where('status', 'ACTIVE')
            ->orderBy('joined_at')
            ->value('team_id');

        return $teamId !== null ? (string) $teamId : null;
    }
}
