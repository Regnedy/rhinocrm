<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('campaign_contacts')) return;

        Schema::table('campaign_contacts', function (Blueprint $table): void {
            if (!Schema::hasColumn('campaign_contacts', 'contact_started_at')) {
                $table->dateTime('contact_started_at')->nullable()->index()->after('last_seen_at');
            }
            if (!Schema::hasColumn('campaign_contacts', 'contact_started_by_user_id')) {
                $table->string('contact_started_by_user_id', 128)->nullable()->index()->after('contact_started_at');
            }
            if (!Schema::hasColumn('campaign_contacts', 'manual_responded_at')) {
                $table->dateTime('manual_responded_at')->nullable()->index()->after('contact_started_by_user_id');
            }
            if (!Schema::hasColumn('campaign_contacts', 'manual_responded_by_user_id')) {
                $table->string('manual_responded_by_user_id', 128)->nullable()->index()->after('manual_responded_at');
            }
            if (!Schema::hasColumn('campaign_contacts', 'commercial_status')) {
                $table->string('commercial_status', 40)->default('UNEXPLORED')->index()->after('manual_responded_by_user_id');
            }
            if (!Schema::hasColumn('campaign_contacts', 'commercial_status_updated_at')) {
                $table->dateTime('commercial_status_updated_at')->nullable()->index()->after('commercial_status');
            }
            if (!Schema::hasColumn('campaign_contacts', 'commercial_status_updated_by_user_id')) {
                $table->string('commercial_status_updated_by_user_id', 128)->nullable()->index()->after('commercial_status_updated_at');
            }
        });
    }

    public function down(): void
    {
        // No destructivo: el historial comercial de eventos se conserva.
    }
};
