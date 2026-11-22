<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('maintenance_action_logs')) {
            Schema::create('maintenance_action_logs', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('actor_user_id', 128)->nullable()->index();
                $table->string('action_type', 40)->index();
                $table->string('action_name', 160)->index();
                $table->string('status', 32)->index();
                $table->json('request_metadata')->nullable();
                $table->longText('result_summary')->nullable();
                $table->text('error_message')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->index(['actor_user_id', 'created_at'], 'maintenance_actor_time_idx');
            });
        }

        if (!Schema::hasTable('ai_diagnostic_requests')) {
            Schema::create('ai_diagnostic_requests', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('actor_user_id', 128)->index();
                $table->string('status', 32)->default('PENDING')->index();
                $table->string('model', 120)->nullable();
                $table->string('provider_request_id', 255)->nullable()->index();
                $table->text('question');
                $table->json('context_manifest')->nullable();
                $table->longText('response_text')->nullable();
                $table->json('usage')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->index(['actor_user_id', 'created_at'], 'ai_diagnostic_actor_time_idx');
            });
        }
    }

    public function down(): void
    {
        // Política no destructiva: las tablas no se eliminan automáticamente.
    }
};
