<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('partner_whatsapp_channels')) {
            return;
        }

        $missing = [];
        foreach ([
            'connection_method',
            'meta_user_id',
            'meta_app_id',
            'access_token_expires_at',
            'embedded_signup_at',
            'webhook_subscribed_at',
        ] as $column) {
            if (!Schema::hasColumn('partner_whatsapp_channels', $column)) {
                $missing[$column] = true;
            }
        }
        if ($missing === []) {
            return;
        }

        Schema::table('partner_whatsapp_channels', function (Blueprint $table) use ($missing): void {
            if (isset($missing['connection_method'])) {
                $table->string('connection_method', 32)->default('MANUAL')->index();
            }
            if (isset($missing['meta_user_id'])) {
                $table->string('meta_user_id', 100)->nullable();
            }
            if (isset($missing['meta_app_id'])) {
                $table->string('meta_app_id', 100)->nullable();
            }
            if (isset($missing['access_token_expires_at'])) {
                $table->timestamp('access_token_expires_at')->nullable();
            }
            if (isset($missing['embedded_signup_at'])) {
                $table->timestamp('embedded_signup_at')->nullable();
            }
            if (isset($missing['webhook_subscribed_at'])) {
                $table->timestamp('webhook_subscribed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Deliberadamente no destructivo: estas columnas documentan y protegen conexiones Meta activas.
    }
};
