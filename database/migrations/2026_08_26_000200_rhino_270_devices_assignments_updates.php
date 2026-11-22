<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('personal_devices')) {
            Schema::create('personal_devices', function (Blueprint $table): void {
                $table->string('id', 80)->primary();
                $table->string('owner_user_id', 128)->index();
                $table->string('owner_partner_id', 64)->nullable()->index();
                $table->string('device_type', 20)->index();
                $table->string('linked_device_id', 64)->nullable()->index();
                $table->string('display_name', 180);
                $table->string('hardware_identifier', 180)->nullable()->index();
                $table->string('model', 120)->nullable();
                $table->string('status', 24)->default('AVAILABLE')->index();
                $table->json('metadata')->nullable();
                $table->dateTime('last_validated_at')->nullable();
                $table->timestamps();
                $table->index(['owner_user_id', 'status'], 'personal_devices_owner_status_idx');
            });
        }

        if (!Schema::hasTable('campaign_device_availability')) {
            Schema::create('campaign_device_availability', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('campaign_id', 64)->index();
                $table->string('personal_device_id', 80)->index();
                $table->string('owner_user_id', 128)->index();
                $table->string('owner_partner_id', 64)->nullable()->index();
                $table->string('status', 20)->default('ACTIVE')->index();
                $table->dateTime('available_at')->nullable();
                $table->dateTime('released_at')->nullable();
                $table->timestamps();
                $table->unique(['campaign_id', 'personal_device_id'], 'campaign_personal_device_unique');
                $table->index(['campaign_id', 'status'], 'campaign_device_availability_active_idx');
            });
        }

        if (!Schema::hasTable('campaign_assignment_history')) {
            Schema::create('campaign_assignment_history', function (Blueprint $table): void {
                $table->string('id', 80)->primary();
                $table->string('campaign_id', 64)->index();
                $table->unsignedBigInteger('campaign_contact_id')->nullable()->index();
                $table->string('contact_id', 64)->index();
                $table->string('from_user_id', 128)->nullable()->index();
                $table->string('to_user_id', 128)->nullable()->index();
                $table->string('changed_by_user_id', 128)->index();
                $table->string('reason', 500)->nullable();
                $table->dateTime('created_at')->index();
            });
        }

        if (!Schema::hasTable('kiosk_versions')) {
            Schema::create('kiosk_versions', function (Blueprint $table): void {
                $table->string('id', 80)->primary();
                $table->string('version_name', 32);
                $table->unsignedInteger('version_code')->unique();
                $table->string('package_name', 180)->default('com.activa7.rhinocrm');
                $table->string('apk_path', 500);
                $table->string('apk_sha256', 64);
                $table->string('signing_sha256', 64)->nullable();
                $table->string('release_type', 20)->default('RECOMMENDED')->index();
                $table->text('notes')->nullable();
                $table->string('status', 20)->default('ACTIVE')->index();
                $table->dateTime('published_at')->index();
                $table->string('created_by_user_id', 128)->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('kiosk_installations')) {
            Schema::create('kiosk_installations', function (Blueprint $table): void {
                $table->string('id', 80)->primary();
                $table->string('kiosk_version_id', 80)->nullable()->index();
                $table->string('user_id', 128)->index();
                $table->string('partner_id', 64)->nullable()->index();
                $table->string('device_fingerprint', 255)->nullable()->index();
                $table->unsignedInteger('installed_version_code');
                $table->string('event_type', 32)->default('INSTALL_REQUESTED')->index();
                $table->string('app_version_name', 32)->nullable();
                $table->json('metadata')->nullable();
                $table->dateTime('created_at')->index();
            });
        }

        // Compatibilidad: las básculas ya vinculadas siguen siendo las mismas; sólo se les agrega
        // una representación personal para la nueva UI 2.7.0.
        if (Schema::hasTable('devices') && Schema::hasColumn('devices', 'linked_by_user_id')) {
            DB::table('devices')
                ->whereNotNull('linked_by_user_id')
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get()
                ->each(function (object $device): void {
                    $userId = trim((string) ($device->linked_by_user_id ?? ''));
                    if ($userId === '') return;
                    $exists = DB::table('personal_devices')
                        ->where('owner_user_id', $userId)
                        ->where('linked_device_id', (string) $device->id)
                        ->exists();
                    if ($exists) return;
                    DB::table('personal_devices')->insert([
                        'id' => 'PDV_'.strtoupper((string) Str::ulid()),
                        'owner_user_id' => $userId,
                        'owner_partner_id' => $device->partner_id ?? null,
                        'device_type' => 'SCALE',
                        'linked_device_id' => (string) $device->id,
                        'display_name' => 'Báscula Rhino',
                        'hardware_identifier' => $device->mac_address ?? null,
                        'model' => $device->model ?? 'RHINO',
                        'status' => 'AVAILABLE',
                        'metadata' => json_encode(['migrated_from' => 'devices'], JSON_UNESCAPED_UNICODE),
                        'last_validated_at' => $device->last_measurement_at ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
        }
    }

    public function down(): void
    {
        // No destructivo por diseño. RHINO 2.7.0 conserva auditoría, versiones y propiedad.
    }
};
