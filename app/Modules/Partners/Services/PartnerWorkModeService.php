<?php

namespace App\Modules\Partners\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;

final class PartnerWorkModeService
{
    public const INDIVIDUAL = 'INDIVIDUAL';
    public const TEAM = 'TEAM';

    public function __construct(private readonly UserContextDTO $context) {}

    public function describe(): array
    {
        $partnerId = $this->partnerId();
        $teamMode = $this->isTeamMode($partnerId);

        return [
            'mode' => $teamMode ? self::TEAM : self::INDIVIDUAL,
            'team_mode_enabled' => $teamMode,
            'can_switch' => $this->context->role === 'PARTNER_ADMIN',
            'automatic_responsible_user_id' => $teamMode ? null : $this->context->userId,
        ];
    }

    public function isTeamMode(?string $partnerId = null): bool
    {
        $partnerId ??= $this->partnerId();

        $row = DB::table('feature_flags')
            ->where('feature', 'team_mode')
            ->where(function ($query) use ($partnerId): void {
                $query->where('partner_id', $partnerId)->orWhereNull('partner_id');
            })
            ->orderByRaw('CASE WHEN partner_id IS NULL THEN 1 ELSE 0 END')
            ->first(['enabled']);

        if ($row !== null) {
            return (bool) $row->enabled;
        }

        return DB::table('users')
                ->where('partner_id', $partnerId)
                ->where('status', 'ACTIVE')
                ->whereNull('deleted_at')
                ->count() > 1
            || DB::table('teams')
                ->where('partner_id', $partnerId)
                ->where('status', 'ACTIVE')
                ->whereNull('deleted_at')
                ->exists();
    }

    public function setMode(string $mode): array
    {
        if ($this->context->role !== 'PARTNER_ADMIN') {
            throw new \RuntimeException('Solo el socio administrador puede cambiar el modo de trabajo.', 403);
        }

        $mode = strtoupper(trim($mode));
        if (!in_array($mode, [self::INDIVIDUAL, self::TEAM], true)) {
            throw new \RuntimeException('Modo de trabajo inválido.', 422);
        }

        $partnerId = $this->partnerId();
        $enabled = $mode === self::TEAM;

        DB::transaction(function () use ($partnerId, $mode, $enabled): void {
            DB::table('feature_flags')->updateOrInsert(
                ['partner_id' => $partnerId, 'feature' => 'team_mode'],
                [
                    'enabled' => $enabled,
                    'configuration' => SqlValue::encode(['mode' => $mode]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            if (!$enabled) {
                DB::table('contacts')
                    ->where('partner_id', $partnerId)
                    ->whereNull('assigned_advisor_id')
                    ->whereNull('deleted_at')
                    ->update([
                        'assigned_advisor_id' => $this->context->userId,
                        'updated_at' => now(),
                    ]);

                $campaignIds = DB::table('campaigns')
                    ->where('partner_id', $partnerId)
                    ->whereNull('responsible_user_id')
                    ->whereNull('deleted_at')
                    ->pluck('id');

                DB::table('campaigns')
                    ->whereIn('id', $campaignIds)
                    ->update([
                        'responsible_user_id' => $this->context->userId,
                        'updated_at' => now(),
                    ]);

                foreach ($campaignIds as $campaignId) {
                    DB::table('campaign_members')->updateOrInsert(
                        ['campaign_id' => (string) $campaignId, 'user_id' => $this->context->userId],
                        [
                            'partner_id' => $partnerId,
                            'member_role' => 'RESPONSIBLE',
                            'status' => 'ACTIVE',
                            'joined_at' => now(),
                            'left_at' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    );
                }
            }
        }, 3);

        return $this->describe();
    }

    private function partnerId(): string
    {
        return $this->context->partnerId
            ?? throw new \RuntimeException('El modo de trabajo requiere un usuario asociado a un socio.', 403);
    }
}
