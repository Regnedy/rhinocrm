<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('campaign_join_tokens')) {
            Schema::create('campaign_join_tokens', function (Blueprint $table): void {
                $table->string('id', 80)->primary();
                $table->string('campaign_id', 64)->index();
                $table->string('campaign_partner_id', 64)->index();
                $table->string('token_hash', 64)->unique();
                $table->string('created_by_user_id', 128)->index();
                $table->unsignedInteger('uses_count')->default(0);
                $table->unsignedInteger('max_uses')->default(100);
                $table->dateTime('expires_at')->index();
                $table->dateTime('revoked_at')->nullable()->index();
                $table->timestamps();
                $table->index(['campaign_id', 'revoked_at', 'expires_at'], 'campaign_join_tokens_active_idx');
            });
        }

        if (Schema::hasTable('campaign_invitations') && !Schema::hasColumn('campaign_invitations', 'source')) {
            Schema::table('campaign_invitations', function (Blueprint $table): void {
                $table->string('source', 20)->default('EMAIL')->index()->after('member_role');
            });
        }

        if (Schema::hasTable('campaign_contacts')) {
            Schema::table('campaign_contacts', function (Blueprint $table): void {
                if (!Schema::hasColumn('campaign_contacts', 'purchase_outcome')) {
                    $table->string('purchase_outcome', 24)->default('UNKNOWN')->index()->after('commercial_status');
                }
                if (!Schema::hasColumn('campaign_contacts', 'purchase_notes')) {
                    $table->string('purchase_notes', 500)->nullable()->after('purchase_outcome');
                }
                if (!Schema::hasColumn('campaign_contacts', 'purchase_outcome_updated_at')) {
                    $table->dateTime('purchase_outcome_updated_at')->nullable()->index()->after('purchase_notes');
                }
                if (!Schema::hasColumn('campaign_contacts', 'purchase_outcome_updated_by_user_id')) {
                    $table->string('purchase_outcome_updated_by_user_id', 128)->nullable()->index()->after('purchase_outcome_updated_at');
                }
            });
        }
    }

    public function down(): void
    {
        // No destructivo: conservar auditoría del evento y tokens ya utilizados.
    }
};
