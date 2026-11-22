<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Services\TenantContextRunner;
use Illuminate\Support\Facades\DB;

final class CampaignAccessService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly TenantContextRunner $runner,
    ) {}

    public function campaign(string $campaignId, bool $lock = false): object
    {
        $query = DB::table('campaigns')->where('id', $campaignId)->whereNull('deleted_at');
        if ($lock) $query->lockForUpdate();
        $campaign = $query->first();
        if ($campaign === null) throw new \RuntimeException('Evento no encontrado.', 404);
        if (!$this->canAccess($campaign)) throw new \RuntimeException('No tienes acceso a este evento.', 403);
        return $campaign;
    }

    public function canAccess(object $campaign): bool
    {
        if ($this->context->isSuperAdmin()) return true;
        $partnerId = $this->context->partnerId;
        if ($partnerId !== null && (string) $campaign->partner_id === $partnerId) {
            if (in_array($this->context->role, ['PARTNER_ADMIN', 'LOCATION_MANAGER'], true)) return true;
            if ((string) ($campaign->responsible_user_id ?? '') === $this->context->userId) return true;
        }

        return DB::table('campaign_members')
            ->where('campaign_id', (string) $campaign->id)
            ->where('user_id', $this->context->userId)
            ->where('status', 'ACTIVE')
            ->exists();
    }

    public function canManage(object $campaign): bool
    {
        if ($this->context->isSuperAdmin()) return true;
        if ($this->context->partnerId === null || (string) $campaign->partner_id !== $this->context->partnerId) return false;
        if (in_array($this->context->role, ['PARTNER_ADMIN', 'LOCATION_MANAGER'], true)) return true;
        if ((string) ($campaign->created_by_user_id ?? '') === $this->context->userId) return true;
        if ((string) ($campaign->responsible_user_id ?? '') === $this->context->userId) return true;

        return DB::table('campaign_members')
            ->where('campaign_id', (string) $campaign->id)
            ->where('user_id', $this->context->userId)
            ->where('status', 'ACTIVE')
            ->whereIn('member_role', ['ORGANIZER', 'RESPONSIBLE'])
            ->exists();
    }

    public function assertManage(object $campaign): void
    {
        if (!$this->canManage($campaign)) {
            throw new \RuntimeException('Solo el organizador puede administrar o cerrar este evento.', 403);
        }
    }

    public function actualPartnerId(): ?string
    {
        if ($this->context->isSuperAdmin()) return $this->context->partnerId;
        $partnerId = DB::table('users')->where('id', $this->context->userId)->whereNull('deleted_at')->value('partner_id');
        return $partnerId !== null ? (string) $partnerId : $this->context->partnerId;
    }

    public function runInCampaign(string|object $campaign, callable $callback): mixed
    {
        $row = is_object($campaign) ? $campaign : $this->campaign($campaign);
        $permissions = array_values(array_unique(array_merge($this->context->permissions, [
            'campaigns.read', 'campaigns.collaborate', 'nutrition.read', 'nutrition.profiles.update',
            'nutrition.plans.generate', 'nutrition.plans.update', 'products.read', 'sales.read', 'sales.create',
        ])));
        $scoped = new UserContextDTO(
            $this->context->userId,
            (string) $row->partner_id,
            'EVENT_COLLABORATOR',
            $row->location_id !== null ? (string) $row->location_id : null,
            $permissions,
        );
        return $this->runner->runWithContext($scoped, $callback);
    }

    public function member(string $campaignId, ?string $userId = null): ?object
    {
        return DB::table('campaign_members')
            ->where('campaign_id', $campaignId)
            ->where('user_id', $userId ?? $this->context->userId)
            ->where('status', 'ACTIVE')
            ->first();
    }
}
