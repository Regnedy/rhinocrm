<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('nutrition_followup_campaigns')) {
            Schema::create('nutrition_followup_campaigns', function (Blueprint $table): void {
                $table->string('id', 80)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('linked_event_id', 64)->nullable()->index();
                $table->string('team_id', 64)->nullable()->index();
                $table->string('responsible_user_id', 128)->nullable()->index();
                $table->string('name', 200);
                $table->string('type', 60)->default('NUTRITION_REENGAGEMENT')->index();
                $table->string('status', 32)->default('DRAFT')->index();
                $table->json('source_filters')->nullable();
                $table->string('whatsapp_template_name', 180)->nullable();
                $table->dateTime('scheduled_at')->nullable()->index();
                $table->string('created_by_user_id', 128)->index();
                $table->dateTime('activated_at')->nullable();
                $table->dateTime('closed_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(
                    ['partner_id', 'status', 'scheduled_at'],
                    'nutrition_followups_partner_status_schedule_idx'
                );
            });
        }

        if (!Schema::hasTable('nutrition_followup_members')) {
            Schema::create('nutrition_followup_members', function (Blueprint $table): void {
                $table->string('id', 100)->primary();
                $table->string('campaign_id', 80)->index();
                $table->string('partner_id', 64)->index();
                $table->string('contact_id', 64)->index();
                $table->string('nutrition_plan_id', 100)->nullable()->index();
                $table->string('personal_report_id', 100)->nullable()->index();
                $table->dateTime('plan_recommended_at')->nullable()->index();
                $table->string('plan_delivery_status', 32)->nullable()->index();
                $table->string('last_measurement_id', 100)->nullable()->index();
                $table->dateTime('last_measurement_at')->nullable()->index();
                $table->string('responsible_user_id', 128)->nullable()->index();
                $table->string('stage', 48)->default('SELECTED')->index();
                $table->string('invitation_status', 32)->default('PENDING')->index();
                $table->json('selection_snapshot')->nullable();
                $table->text('notes')->nullable();
                $table->dateTime('invited_at')->nullable();
                $table->dateTime('responded_at')->nullable();
                $table->dateTime('confirmed_at')->nullable();
                $table->dateTime('attended_at')->nullable();
                $table->dateTime('reweighed_at')->nullable();
                $table->dateTime('interested_at')->nullable();
                $table->string('sale_id', 64)->nullable()->index();
                $table->timestamps();
                $table->unique(
                    ['campaign_id', 'contact_id'],
                    'nutrition_followup_campaign_contact_unique'
                );
                $table->index(
                    ['partner_id', 'campaign_id', 'stage'],
                    'nutrition_followup_partner_campaign_stage_idx'
                );
            });
        }

        if (!Schema::hasTable('nutrition_followup_stage_history')) {
            Schema::create('nutrition_followup_stage_history', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('campaign_id', 80)->index();
                $table->string('member_id', 100)->index();
                $table->string('partner_id', 64)->index();
                $table->string('contact_id', 64)->index();
                $table->string('from_stage', 48)->nullable();
                $table->string('to_stage', 48)->index();
                $table->string('source', 40)->default('MANUAL')->index();
                $table->string('changed_by_user_id', 128)->nullable()->index();
                $table->text('note')->nullable();
                $table->dateTime('created_at')->index();
            });
        }

        if (!Schema::hasTable('nutrition_followup_messages')) {
            Schema::create('nutrition_followup_messages', function (Blueprint $table): void {
                $table->string('id', 100)->primary();
                $table->string('campaign_id', 80)->index();
                $table->string('member_id', 100)->index();
                $table->string('partner_id', 64)->index();
                $table->string('contact_id', 64)->index();
                $table->string('message_kind', 40)->default('INVITATION')->index();
                $table->unsignedSmallInteger('attempt_no')->default(1);
                $table->string('recipient', 40);
                $table->string('status', 32)->default('QUEUED')->index();
                $table->string('external_id', 255)->nullable()->unique();
                $table->string('template_name', 180)->nullable();
                $table->text('rendered_body')->nullable();
                $table->string('skip_reason', 120)->nullable()->index();
                $table->text('last_error')->nullable();
                $table->dateTime('queued_at')->nullable();
                $table->dateTime('processing_at')->nullable();
                $table->dateTime('sent_at')->nullable();
                $table->dateTime('delivered_at')->nullable();
                $table->dateTime('read_at')->nullable();
                $table->dateTime('failed_at')->nullable();
                $table->timestamps();
                $table->unique(
                    ['member_id', 'message_kind', 'attempt_no'],
                    'nutrition_followup_member_kind_attempt_unique'
                );
                $table->index(
                    ['campaign_id', 'status'],
                    'nutrition_followup_messages_campaign_status_idx'
                );
            });
        }

        if (Schema::hasTable('feature_flags') && Schema::hasTable('partners')) {
            $partnerIds = DB::table('partners')->whereNull('deleted_at')->pluck('id');
            foreach ($partnerIds as $partnerId) {
                DB::table('feature_flags')->updateOrInsert(
                    ['partner_id' => $partnerId, 'feature' => 'nutrition_followup_campaigns'],
                    [
                        'enabled' => true,
                        'configuration' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'permissions')) {
            $permissionsByRole = [
                'PARTNER_ADMIN' => [
                    'nutrition_campaigns.read',
                    'nutrition_campaigns.create',
                    'nutrition_campaigns.update',
                    'nutrition_campaigns.invite',
                    'nutrition_campaigns.stage',
                    'nutrition_campaigns.report',
                ],
                'LOCATION_MANAGER' => [
                    'nutrition_campaigns.read',
                    'nutrition_campaigns.create',
                    'nutrition_campaigns.update',
                    'nutrition_campaigns.invite',
                    'nutrition_campaigns.stage',
                    'nutrition_campaigns.report',
                ],
                'TEAM_LEADER' => [
                    'nutrition_campaigns.read',
                    'nutrition_campaigns.create',
                    'nutrition_campaigns.update',
                    'nutrition_campaigns.invite',
                    'nutrition_campaigns.stage',
                    'nutrition_campaigns.report',
                ],
                'ADVISOR' => [
                    'nutrition_campaigns.read',
                    'nutrition_campaigns.stage',
                ],
            ];

            DB::table('users')
                ->whereIn('role', array_keys($permissionsByRole))
                ->get(['id', 'role', 'permissions'])
                ->chunk(200)
                ->each(function ($users) use ($permissionsByRole): void {
                    foreach ($users as $user) {
                        $current = is_array($user->permissions ?? null)
                            ? $user->permissions
                            : json_decode((string) ($user->permissions ?? '[]'), true);
                        $current = is_array($current) ? $current : [];
                        $merged = array_values(array_unique(array_merge(
                            $current,
                            $permissionsByRole[(string) $user->role] ?? [],
                        )));
                        DB::table('users')->where('id', $user->id)->update([
                            'permissions' => json_encode(
                                $merged,
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                            ),
                            'updated_at' => now(),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Política Rhino: no se eliminan tablas ni datos automáticamente.
        // Una reversión destructiva requiere respaldo y autorización expresa.
    }
};
