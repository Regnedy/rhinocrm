<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('campaigns')) {
            Schema::create('campaigns', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('location_id', 64)->nullable()->index();
                $table->string('team_id', 64)->nullable()->index();
                $table->string('responsible_user_id', 128)->nullable()->index();
                $table->string('name', 200);
                $table->string('type', 60)->default('WEIGHING_EVENT')->index();
                $table->string('venue_name', 180)->nullable();
                $table->string('address', 300)->nullable();
                $table->string('city', 120)->nullable();
                $table->string('state', 120)->nullable();
                $table->dateTime('starts_at')->index();
                $table->dateTime('ends_at')->nullable()->index();
                $table->string('status', 32)->default('SCHEDULED')->index();
                $table->unsignedInteger('prospects_target')->default(0);
                $table->unsignedInteger('measurements_target')->default(0);
                $table->unsignedBigInteger('budget_minor')->default(0);
                $table->string('currency', 3)->default('MXN');
                $table->text('notes')->nullable();
                $table->string('created_by_user_id', 128)->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['partner_id', 'status', 'starts_at'], 'campaigns_partner_status_start_idx');
            });
        }

        if (!Schema::hasTable('campaign_members')) {
            Schema::create('campaign_members', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('campaign_id', 64)->index();
                $table->string('partner_id', 64)->index();
                $table->string('user_id', 128)->index();
                $table->string('member_role', 32)->default('STAFF');
                $table->string('status', 32)->default('ACTIVE')->index();
                $table->timestamp('joined_at')->useCurrent();
                $table->timestamp('left_at')->nullable();
                $table->timestamps();
                $table->unique(['campaign_id', 'user_id'], 'campaign_members_campaign_user_unique');
                $table->index(['partner_id', 'user_id', 'status'], 'campaign_members_partner_user_status_idx');
            });
        }

        if (!Schema::hasTable('campaign_devices')) {
            Schema::create('campaign_devices', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('campaign_id', 64)->index();
                $table->string('partner_id', 64)->index();
                $table->string('device_id', 64)->index();
                $table->string('status', 32)->default('ACTIVE')->index();
                $table->timestamp('linked_at')->useCurrent();
                $table->timestamp('unlinked_at')->nullable();
                $table->timestamps();
                $table->unique(['campaign_id', 'device_id'], 'campaign_devices_campaign_device_unique');
                $table->index(['partner_id', 'device_id', 'status'], 'campaign_devices_partner_device_status_idx');
            });
        }

        if (!Schema::hasTable('campaign_contacts')) {
            Schema::create('campaign_contacts', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('campaign_id', 64)->index();
                $table->string('partner_id', 64)->index();
                $table->string('contact_id', 64)->index();
                $table->string('registered_by_user_id', 128)->nullable()->index();
                $table->string('assigned_advisor_id', 128)->nullable()->index();
                $table->string('first_measurement_id', 100)->nullable()->index();
                $table->string('last_measurement_id', 100)->nullable()->index();
                $table->string('participant_type', 40)->default('NEW_PROSPECT')->index();
                $table->boolean('is_new_contact')->default(false)->index();
                $table->unsignedInteger('measurements_count')->default(0);
                $table->dateTime('first_seen_at')->index();
                $table->dateTime('last_seen_at')->index();
                $table->timestamp('converted_at')->nullable()->index();
                $table->string('sale_id', 64)->nullable()->index();
                $table->timestamps();
                $table->unique(['campaign_id', 'contact_id'], 'campaign_contacts_campaign_contact_unique');
                $table->index(['partner_id', 'campaign_id', 'participant_type'], 'campaign_contacts_partner_campaign_type_idx');
            });
        }

        $this->addNullableColumn('measurements', 'campaign_id', function (Blueprint $table): void {
            $table->string('campaign_id', 64)->nullable()->index()->after('contact_id');
        });
        $this->addNullableColumn('contacts', 'origin_campaign_id', function (Blueprint $table): void {
            $table->string('origin_campaign_id', 64)->nullable()->index()->after('origin_team_id');
        });
        $this->addNullableColumn('tasks', 'campaign_id', function (Blueprint $table): void {
            $table->string('campaign_id', 64)->nullable()->index()->after('team_id');
        });
        $this->addNullableColumn('sales', 'origin_campaign_id', function (Blueprint $table): void {
            $table->string('origin_campaign_id', 64)->nullable()->index()->after('origin_device_id');
        });

        if (Schema::hasTable('partners') && Schema::hasTable('locations')) {
            $partners = DB::table('partners')->whereNull('deleted_at')->pluck('id');
            foreach ($partners as $partnerId) {
                $exists = DB::table('locations')
                    ->where('partner_id', $partnerId)
                    ->whereNull('deleted_at')
                    ->exists();
                if (!$exists) {
                    $locationId = 'LOC_PRINCIPAL_'.strtoupper(substr(hash('sha256', (string) $partnerId), 0, 20));
                    DB::table('locations')->insertOrIgnore([
                        'id' => $locationId,
                        'partner_id' => $partnerId,
                        'name' => 'Principal',
                        'city' => null,
                        'state' => null,
                        'status' => 'ACTIVE',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                if (Schema::hasTable('feature_flags')) {
                    DB::table('feature_flags')->updateOrInsert(
                        ['partner_id' => $partnerId, 'feature' => 'campaigns'],
                        ['enabled' => true, 'configuration' => null, 'created_at' => now(), 'updated_at' => now()],
                    );
                }
            }
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'permissions')) {
            $permissionsByRole = [
                'PARTNER_ADMIN' => ['campaigns.read', 'campaigns.create', 'campaigns.update', 'campaigns.activate', 'campaigns.participants.create', 'campaigns.report'],
                'LOCATION_MANAGER' => ['campaigns.read', 'campaigns.create', 'campaigns.update', 'campaigns.activate', 'campaigns.participants.create', 'campaigns.report'],
                'TEAM_LEADER' => ['campaigns.read', 'campaigns.create', 'campaigns.update', 'campaigns.activate', 'campaigns.participants.create', 'campaigns.report'],
                'ADVISOR' => ['campaigns.read', 'campaigns.participants.create'],
                'OPERATOR' => ['campaigns.read', 'campaigns.participants.create'],
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
                        $merged = array_values(array_unique(array_merge($current, $permissionsByRole[(string) $user->role] ?? [])));
                        DB::table('users')->where('id', $user->id)->update([
                            'permissions' => json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'updated_at' => now(),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Intencionalmente no destructivo: esta migración nunca elimina datos de producción.
    }

    private function addNullableColumn(string $tableName, string $column, callable $definition): void
    {
        if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, $column)) {
            Schema::table($tableName, $definition);
        }
    }
};
