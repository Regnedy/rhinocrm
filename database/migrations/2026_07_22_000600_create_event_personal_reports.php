<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contacts') && !Schema::hasColumn('contacts', 'public_code')) {
            Schema::table('contacts', function (Blueprint $table): void {
                $table->string('public_code', 20)->nullable()->unique()->after('id');
            });
        }

        if (!Schema::hasTable('health_data_consents')) {
            Schema::create('health_data_consents', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('partner_id', 64)->index();
                $table->string('contact_id', 64)->index();
                $table->string('purpose', 80)->index();
                $table->string('status', 24)->default('GRANTED')->index();
                $table->string('source', 60)->index();
                $table->text('evidence_text')->nullable();
                $table->json('evidence')->nullable();
                $table->dateTime('granted_at')->index();
                $table->dateTime('revoked_at')->nullable()->index();
                $table->timestamps();
                $table->unique(['contact_id', 'purpose'], 'health_consents_contact_purpose_unique');
            });
        }

        if (!Schema::hasTable('whatsapp_contact_links')) {
            Schema::create('whatsapp_contact_links', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('partner_id', 64)->index();
                $table->string('contact_id', 64)->index();
                $table->string('public_code', 20)->index();
                $table->string('phone_number', 40)->index();
                $table->string('status', 24)->default('ACTIVE')->index();
                $table->dateTime('verified_at')->nullable();
                $table->dateTime('last_inbound_at')->nullable()->index();
                $table->dateTime('report_consent_at')->nullable()->index();
                $table->dateTime('marketing_consent_at')->nullable()->index();
                $table->dateTime('revoked_at')->nullable()->index();
                $table->text('consent_text')->nullable();
                $table->timestamps();
                $table->unique(['partner_id', 'contact_id'], 'wa_links_partner_contact_unique');
                $table->unique(['partner_id', 'phone_number'], 'wa_links_partner_phone_unique');
            });
        }

        if (!Schema::hasTable('whatsapp_inbound_messages')) {
            Schema::create('whatsapp_inbound_messages', function (Blueprint $table): void {
                $table->string('id', 180)->primary();
                $table->string('partner_id', 64)->nullable()->index();
                $table->string('contact_id', 64)->nullable()->index();
                $table->string('from_phone_number', 40)->index();
                $table->string('message_type', 40)->default('TEXT')->index();
                $table->string('public_code', 20)->nullable()->index();
                $table->string('link_status', 40)->default('UNMATCHED')->index();
                $table->text('body')->nullable();
                $table->json('metadata')->nullable();
                $table->dateTime('received_at')->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('event_report_batches')) {
            Schema::create('event_report_batches', function (Blueprint $table): void {
                $table->string('id', 80)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('campaign_id', 64)->index();
                $table->string('requested_by_user_id', 128)->index();
                $table->string('status', 32)->default('QUEUED')->index();
                $table->unsignedInteger('eligible_count')->default(0);
                $table->unsignedInteger('excluded_count')->default(0);
                $table->unsignedInteger('queued_count')->default(0);
                $table->unsignedInteger('sent_count')->default(0);
                $table->unsignedInteger('delivered_count')->default(0);
                $table->unsignedInteger('read_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->unsignedInteger('skipped_count')->default(0);
                $table->dateTime('started_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->timestamps();
                $table->index(['partner_id', 'campaign_id', 'created_at'], 'event_report_batches_campaign_time_idx');
            });
        }

        if (!Schema::hasTable('personal_reports')) {
            Schema::create('personal_reports', function (Blueprint $table): void {
                $table->string('id', 100)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('campaign_id', 64)->index();
                $table->string('contact_id', 64)->index();
                $table->string('measurement_id', 100)->index();
                $table->string('public_code', 20)->index();
                $table->string('report_type', 60)->default('WEIGHING_ORIENTATION');
                $table->string('status', 32)->default('QUEUED')->index();
                $table->string('algorithm_version', 60)->default('rhino-orientation-v1');
                $table->string('storage_disk', 40)->nullable();
                $table->string('file_path', 500)->nullable();
                $table->string('content_hash', 64)->nullable();
                $table->text('last_error')->nullable();
                $table->dateTime('generated_at')->nullable();
                $table->timestamps();
                $table->unique(['campaign_id', 'contact_id', 'measurement_id', 'algorithm_version'], 'personal_reports_source_unique');
            });
        }

        if (!Schema::hasTable('report_deliveries')) {
            Schema::create('report_deliveries', function (Blueprint $table): void {
                $table->string('id', 100)->primary();
                $table->string('batch_id', 80)->index();
                $table->string('report_id', 100)->index();
                $table->string('partner_id', 64)->index();
                $table->string('campaign_id', 64)->index();
                $table->string('contact_id', 64)->index();
                $table->string('channel', 32)->default('WHATSAPP')->index();
                $table->string('recipient', 40);
                $table->string('status', 32)->default('QUEUED')->index();
                $table->string('external_id', 255)->nullable()->unique();
                $table->string('media_id', 255)->nullable();
                $table->string('skip_reason', 100)->nullable()->index();
                $table->text('last_error')->nullable();
                $table->dateTime('queued_at')->nullable();
                $table->dateTime('processing_at')->nullable();
                $table->dateTime('sent_at')->nullable();
                $table->dateTime('delivered_at')->nullable();
                $table->dateTime('read_at')->nullable();
                $table->dateTime('failed_at')->nullable();
                $table->timestamps();
                $table->unique(['report_id', 'channel'], 'report_deliveries_report_channel_unique');
                $table->index(['batch_id', 'status'], 'report_deliveries_batch_status_idx');
            });
        }

        if (!Schema::hasTable('nutrition_profiles')) {
            Schema::create('nutrition_profiles', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('partner_id', 64)->index();
                $table->string('contact_id', 64)->unique();
                $table->string('status', 32)->default('INCOMPLETE')->index();
                $table->string('goal', 80)->nullable();
                $table->string('activity_level', 40)->nullable();
                $table->unsignedTinyInteger('meals_per_day')->nullable();
                $table->json('preferences')->nullable();
                $table->json('allergies')->nullable();
                $table->json('health_flags')->nullable();
                $table->json('medications')->nullable();
                $table->json('schedule_preferences')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('whatsapp_messages')) {
            if (!Schema::hasColumn('whatsapp_messages', 'direction')) {
                Schema::table('whatsapp_messages', function (Blueprint $table): void {
                    $table->string('direction', 16)->default('OUTBOUND')->index()->after('contact_id');
                });
            }
            if (!Schema::hasColumn('whatsapp_messages', 'message_type')) {
                Schema::table('whatsapp_messages', function (Blueprint $table): void {
                    $table->string('message_type', 40)->default('TEMPLATE')->index()->after('direction');
                });
            }
            if (!Schema::hasColumn('whatsapp_messages', 'media_id')) {
                Schema::table('whatsapp_messages', function (Blueprint $table): void {
                    $table->string('media_id', 255)->nullable()->after('external_id');
                });
            }
        }
    }

    public function down(): void
    {
        // Política Rhino: no se eliminan tablas ni datos automáticamente.
        // Una reversión destructiva requiere respaldo y autorización explícita.
    }
};
