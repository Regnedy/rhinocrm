<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('partner_whatsapp_channels')) {
            Schema::create('partner_whatsapp_channels', function (Blueprint $table): void {
                $table->string('id', 80)->primary();
                $table->string('partner_id', 64)->unique();
                $table->string('display_phone_number', 40)->nullable();
                $table->string('phone_number_id', 100)->unique();
                $table->string('waba_id', 100)->nullable();
                $table->longText('access_token_ciphertext');
                $table->string('pdf_template_name', 120)->nullable();
                $table->string('images_template_name', 120)->nullable();
                $table->string('template_language', 16)->default('es_MX');
                $table->string('status', 32)->default('ACTIVE')->index();
                $table->timestamp('access_token_updated_at')->nullable();
                $table->timestamp('last_tested_at')->nullable();
                $table->text('last_error')->nullable();
                $table->string('created_by_user_id', 128)->nullable()->index();
                $table->string('updated_by_user_id', 128)->nullable()->index();
                $table->timestamps();
                $table->index(['partner_id', 'status'], 'partner_whatsapp_channel_state_idx');
            });
        }

        $this->grantPermission();
    }

    public function down(): void
    {
        // Deliberadamente no destructivo: contiene credenciales operativas cifradas por socio.
    }

    private function grantPermission(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'permissions')) {
            return;
        }

        DB::table('users')
            ->where('role', 'PARTNER_ADMIN')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunk(200, function ($users): void {
                foreach ($users as $user) {
                    $permissions = json_decode((string) ($user->permissions ?? '[]'), true);
                    if (!is_array($permissions) || $permissions === []) {
                        continue;
                    }
                    if (!in_array('organization.whatsapp.manage', $permissions, true)) {
                        $permissions[] = 'organization.whatsapp.manage';
                    }
                    DB::table('users')->where('id', $user->id)->update([
                        'permissions' => json_encode(array_values(array_unique(array_map('strval', $permissions))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => now(),
                    ]);
                }
            });
    }
};
