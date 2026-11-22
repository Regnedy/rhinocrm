<?php

use App\Support\Database\SqlValue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('feature_flags') || !Schema::hasTable('partners')) {
            return;
        }

        DB::table('feature_flags')->updateOrInsert(
            ['partner_id' => null, 'feature' => 'team_mode'],
            [
                'enabled' => false,
                'configuration' => SqlValue::encode(['mode' => 'INDIVIDUAL']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $partners = DB::table('partners')->whereNull('deleted_at')->pluck('id');

        foreach ($partners as $partnerId) {
            $activeUsers = Schema::hasTable('users')
                ? DB::table('users')
                    ->where('partner_id', $partnerId)
                    ->where('status', 'ACTIVE')
                    ->whereNull('deleted_at')
                    ->count()
                : 0;

            $hasTeams = Schema::hasTable('teams')
                && DB::table('teams')
                    ->where('partner_id', $partnerId)
                    ->where('status', 'ACTIVE')
                    ->whereNull('deleted_at')
                    ->exists();

            $teamMode = $activeUsers > 1 || $hasTeams;

            DB::table('feature_flags')->updateOrInsert(
                ['partner_id' => $partnerId, 'feature' => 'team_mode'],
                [
                    'enabled' => $teamMode,
                    'configuration' => SqlValue::encode([
                        'mode' => $teamMode ? 'TEAM' : 'INDIVIDUAL',
                        'inferred_during_upgrade' => true,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            if (!$teamMode && Schema::hasTable('users')) {
                $adminId = DB::table('users')
                    ->where('partner_id', $partnerId)
                    ->where('role', 'PARTNER_ADMIN')
                    ->where('status', 'ACTIVE')
                    ->whereNull('deleted_at')
                    ->value('id');

                if ($adminId !== null && Schema::hasTable('contacts')) {
                    DB::table('contacts')
                        ->where('partner_id', $partnerId)
                        ->whereNull('assigned_advisor_id')
                        ->whereNull('deleted_at')
                        ->update([
                            'assigned_advisor_id' => (string) $adminId,
                            'updated_at' => now(),
                        ]);
                }

                if ($adminId !== null && Schema::hasTable('campaigns')) {
                    $campaignIds = DB::table('campaigns')
                        ->where('partner_id', $partnerId)
                        ->whereNull('responsible_user_id')
                        ->whereNull('deleted_at')
                        ->pluck('id');

                    DB::table('campaigns')
                        ->whereIn('id', $campaignIds)
                        ->update([
                            'responsible_user_id' => (string) $adminId,
                            'updated_at' => now(),
                        ]);

                    if (Schema::hasTable('campaign_members')) {
                        foreach ($campaignIds as $campaignId) {
                            DB::table('campaign_members')->updateOrInsert(
                                ['campaign_id' => (string) $campaignId, 'user_id' => (string) $adminId],
                                [
                                    'partner_id' => (string) $partnerId,
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
                }
            }
        }
    }

    public function down(): void
    {
        // No destructivo: el modo puede volver a cambiarse desde el CRM.
    }
};
