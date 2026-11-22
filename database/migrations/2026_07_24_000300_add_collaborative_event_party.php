<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createInvitations();
        $this->createActivity();
        $this->extendCampaigns();
        $this->extendMembers();
        $this->extendParticipants();
        $this->extendMeasurements();
        $this->backfill();
        $this->grantPermissions();
    }

    public function down(): void
    {
        // No destructivo: el historial de eventos y propiedad de contactos se conserva.
    }

    private function createInvitations(): void
    {
        if (Schema::hasTable('campaign_invitations')) return;

        Schema::create('campaign_invitations', function (Blueprint $table): void {
            $table->string('id', 80)->primary();
            $table->string('campaign_id', 64)->index();
            $table->string('campaign_partner_id', 64)->index();
            $table->string('invited_partner_id', 64)->nullable()->index();
            $table->string('invited_user_id', 128)->index();
            $table->string('invited_email', 255)->index();
            $table->string('invited_name', 180)->nullable();
            $table->string('member_role', 32)->default('COLLABORATOR');
            $table->string('status', 24)->default('PENDING')->index();
            $table->string('invited_by_user_id', 128)->index();
            $table->dateTime('responded_at')->nullable()->index();
            $table->dateTime('accepted_at')->nullable()->index();
            $table->dateTime('declined_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'invited_user_id'], 'campaign_invitation_user_unique');
            $table->index(['invited_user_id', 'status', 'created_at'], 'campaign_invitation_inbox_idx');
        });
    }

    private function createActivity(): void
    {
        if (Schema::hasTable('campaign_activity')) return;

        Schema::create('campaign_activity', function (Blueprint $table): void {
            $table->string('id', 80)->primary();
            $table->string('campaign_id', 64)->index();
            $table->string('campaign_partner_id', 64)->index();
            $table->string('actor_user_id', 128)->nullable()->index();
            $table->string('actor_partner_id', 64)->nullable()->index();
            $table->string('activity_type', 60)->index();
            $table->string('contact_id', 64)->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['campaign_id', 'created_at'], 'campaign_activity_event_time_idx');
        });
    }

    private function extendCampaigns(): void
    {
        if (!Schema::hasTable('campaigns')) return;

        Schema::table('campaigns', function (Blueprint $table): void {
            if (!Schema::hasColumn('campaigns', 'closing_notes')) {
                $table->text('closing_notes')->nullable()->after('notes');
            }
            if (!Schema::hasColumn('campaigns', 'closed_by_user_id')) {
                $table->string('closed_by_user_id', 128)->nullable()->index()->after('closed_at');
            }
            if (!Schema::hasColumn('campaigns', 'closure_snapshot')) {
                $table->json('closure_snapshot')->nullable()->after('closed_by_user_id');
            }
        });
    }

    private function extendMembers(): void
    {
        if (!Schema::hasTable('campaign_members')) return;

        Schema::table('campaign_members', function (Blueprint $table): void {
            if (!Schema::hasColumn('campaign_members', 'member_partner_id')) {
                $table->string('member_partner_id', 64)->nullable()->index()->after('partner_id');
            }
            if (!Schema::hasColumn('campaign_members', 'invitation_id')) {
                $table->string('invitation_id', 80)->nullable()->index()->after('member_role');
            }
            if (!Schema::hasColumn('campaign_members', 'invited_by_user_id')) {
                $table->string('invited_by_user_id', 128)->nullable()->index()->after('invitation_id');
            }
            if (!Schema::hasColumn('campaign_members', 'accepted_at')) {
                $table->dateTime('accepted_at')->nullable()->index()->after('joined_at');
            }
        });
    }

    private function extendParticipants(): void
    {
        if (!Schema::hasTable('campaign_contacts')) return;

        Schema::table('campaign_contacts', function (Blueprint $table): void {
            if (!Schema::hasColumn('campaign_contacts', 'registered_by_partner_id')) {
                $table->string('registered_by_partner_id', 64)->nullable()->index()->after('registered_by_user_id');
            }
            if (!Schema::hasColumn('campaign_contacts', 'diet_claimed_by_user_id')) {
                $table->string('diet_claimed_by_user_id', 128)->nullable()->index()->after('assigned_advisor_id');
            }
            if (!Schema::hasColumn('campaign_contacts', 'diet_claimed_by_partner_id')) {
                $table->string('diet_claimed_by_partner_id', 64)->nullable()->index()->after('diet_claimed_by_user_id');
            }
            if (!Schema::hasColumn('campaign_contacts', 'diet_claimed_at')) {
                $table->dateTime('diet_claimed_at')->nullable()->index()->after('diet_claimed_by_partner_id');
            }
            if (!Schema::hasColumn('campaign_contacts', 'diet_generated_by_user_id')) {
                $table->string('diet_generated_by_user_id', 128)->nullable()->index()->after('diet_claimed_at');
            }
            if (!Schema::hasColumn('campaign_contacts', 'diet_generated_by_partner_id')) {
                $table->string('diet_generated_by_partner_id', 64)->nullable()->index()->after('diet_generated_by_user_id');
            }
            if (!Schema::hasColumn('campaign_contacts', 'owner_user_id')) {
                $table->string('owner_user_id', 128)->nullable()->index()->after('diet_generated_by_partner_id');
            }
            if (!Schema::hasColumn('campaign_contacts', 'owner_partner_id')) {
                $table->string('owner_partner_id', 64)->nullable()->index()->after('owner_user_id');
            }
            if (!Schema::hasColumn('campaign_contacts', 'nutrition_plan_id')) {
                $table->string('nutrition_plan_id', 100)->nullable()->index()->after('owner_partner_id');
            }
            if (!Schema::hasColumn('campaign_contacts', 'nutrition_generated_at')) {
                $table->dateTime('nutrition_generated_at')->nullable()->index()->after('nutrition_plan_id');
            }
            if (!Schema::hasColumn('campaign_contacts', 'attention_status')) {
                $table->string('attention_status', 32)->default('PENDING_DIET')->index()->after('participant_type');
            }
            if (!Schema::hasColumn('campaign_contacts', 'closure_disposition')) {
                $table->string('closure_disposition', 40)->nullable()->index()->after('sale_id');
            }
            if (!Schema::hasColumn('campaign_contacts', 'closed_at')) {
                $table->dateTime('closed_at')->nullable()->index()->after('closure_disposition');
            }
        });
    }

    private function extendMeasurements(): void
    {
        if (!Schema::hasTable('measurements')) return;

        Schema::table('measurements', function (Blueprint $table): void {
            if (!Schema::hasColumn('measurements', 'captured_by_user_id')) {
                $table->string('captured_by_user_id', 128)->nullable()->index()->after('campaign_id');
            }
            if (!Schema::hasColumn('measurements', 'captured_by_partner_id')) {
                $table->string('captured_by_partner_id', 64)->nullable()->index()->after('captured_by_user_id');
            }
        });
    }

    private function backfill(): void
    {
        if (Schema::hasTable('campaign_members') && Schema::hasColumn('campaign_members', 'member_partner_id')) {
            DB::table('campaign_members')->whereNull('member_partner_id')->update([
                'member_partner_id' => DB::raw('partner_id'),
                'accepted_at' => DB::raw('COALESCE(accepted_at, joined_at)'),
            ]);
        }

        if (Schema::hasTable('campaign_contacts') && Schema::hasColumn('campaign_contacts', 'registered_by_partner_id')) {
            DB::table('campaign_contacts')->whereNull('registered_by_partner_id')->update([
                'registered_by_partner_id' => DB::raw('partner_id'),
            ]);
            DB::table('campaign_contacts')->whereNull('attention_status')->update(['attention_status' => 'PENDING_DIET']);
        }
    }

    private function grantPermissions(): void
    {
        $managementRoles = ['PARTNER_ADMIN', 'LOCATION_MANAGER', 'TEAM_LEADER'];

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'permissions')) {
            DB::table('users')->whereIn('role', array_merge($managementRoles, ['ADVISOR', 'OPERATOR']))
                ->orderBy('id')->chunkById(200, function ($users) use ($managementRoles): void {
                    foreach ($users as $user) {
                        $current = json_decode((string) ($user->permissions ?? '[]'), true);
                        $current = is_array($current) ? $current : [];
                        // Un arreglo vacío indica que la cuenta hereda los permisos por rol.
                        // No debemos convertirlo en un conjunto explícito incompleto.
                        if ($current === [] || !in_array('campaigns.read', $current, true)) continue;

                        $add = ['campaigns.collaborate'];
                        if (in_array((string) $user->role, $managementRoles, true)
                            && array_intersect(['campaigns.create', 'campaigns.update', 'campaigns.activate'], $current) !== []) {
                            $add[] = 'campaigns.invite';
                            $add[] = 'campaigns.close';
                        }
                        if (in_array('sales.read', $current, true) && in_array('sales.create', $current, true)) {
                            $add[] = 'campaigns.sales';
                        }

                        DB::table('users')->where('id', $user->id)->update([
                            'permissions' => json_encode(array_values(array_unique(array_merge($current, $add))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'updated_at' => now(),
                        ]);
                    }
                }, 'id');
        }

        if (Schema::hasTable('partner_permission_policies')) {
            DB::table('partner_permission_policies')->orderBy('partner_id')->chunk(200, function ($rows): void {
                foreach ($rows as $row) {
                    $current = json_decode((string) ($row->permissions ?? '[]'), true);
                    $current = is_array($current) ? $current : [];
                    if (!in_array('campaigns.read', $current, true)) continue;

                    $add = ['campaigns.collaborate'];
                    if (array_intersect(['campaigns.create', 'campaigns.update', 'campaigns.activate'], $current) !== []) {
                        $add[] = 'campaigns.invite';
                        $add[] = 'campaigns.close';
                    }
                    if (in_array('sales.read', $current, true) && in_array('sales.create', $current, true)) {
                        $add[] = 'campaigns.sales';
                    }

                    DB::table('partner_permission_policies')->where('partner_id', $row->partner_id)->update([
                        'permissions' => json_encode(array_values(array_unique(array_merge($current, $add))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => now(),
                    ]);
                }
            });
        }
    }
};
