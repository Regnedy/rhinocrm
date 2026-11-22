<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contacts')) {
            Schema::table('contacts', function (Blueprint $table): void {
                if (!Schema::hasColumn('contacts', 'whatsapp_status')) {
                    $table->string('whatsapp_status', 24)->default('VERIFIED')->index()->after('whatsapp_normalized');
                }
                if (!Schema::hasColumn('contacts', 'whatsapp_verified_at')) {
                    $table->dateTime('whatsapp_verified_at')->nullable()->index()->after('whatsapp_status');
                }
            });

            DB::table('contacts')
                ->where(function ($query): void {
                    $query->where('whatsapp', '')->orWhere('whatsapp_normalized', 'like', 'PENDING_%');
                })
                ->update(['whatsapp_status' => 'PENDING', 'whatsapp_verified_at' => null]);

            DB::table('contacts')
                ->where('whatsapp', '!=', '')
                ->where('whatsapp_normalized', 'not like', 'PENDING_%')
                ->whereNull('whatsapp_verified_at')
                ->update(['whatsapp_status' => 'VERIFIED', 'whatsapp_verified_at' => now()]);
        }

        if (Schema::hasTable('campaign_contacts')) {
            Schema::table('campaign_contacts', function (Blueprint $table): void {
                if (!Schema::hasColumn('campaign_contacts', 'whatsapp_capture_token')) {
                    $table->string('whatsapp_capture_token', 80)->nullable()->unique()->after('attention_status');
                }
                if (!Schema::hasColumn('campaign_contacts', 'whatsapp_capture_status')) {
                    $table->string('whatsapp_capture_status', 24)->default('PENDING')->index()->after('whatsapp_capture_token');
                }
                if (!Schema::hasColumn('campaign_contacts', 'whatsapp_capture_expires_at')) {
                    $table->dateTime('whatsapp_capture_expires_at')->nullable()->index()->after('whatsapp_capture_status');
                }
                if (!Schema::hasColumn('campaign_contacts', 'whatsapp_qr_shown_at')) {
                    $table->dateTime('whatsapp_qr_shown_at')->nullable()->after('whatsapp_capture_expires_at');
                }
                if (!Schema::hasColumn('campaign_contacts', 'whatsapp_vcard_downloaded_at')) {
                    $table->dateTime('whatsapp_vcard_downloaded_at')->nullable()->after('whatsapp_qr_shown_at');
                }
                if (!Schema::hasColumn('campaign_contacts', 'whatsapp_opened_at')) {
                    $table->dateTime('whatsapp_opened_at')->nullable()->after('whatsapp_vcard_downloaded_at');
                }
                if (!Schema::hasColumn('campaign_contacts', 'whatsapp_confirmed_at')) {
                    $table->dateTime('whatsapp_confirmed_at')->nullable()->after('whatsapp_opened_at');
                }
            });

            DB::table('campaign_contacts')
                ->whereExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('contacts')
                        ->whereColumn('contacts.id', 'campaign_contacts.contact_id')
                        ->where('contacts.whatsapp_status', 'VERIFIED');
                })
                ->update(['whatsapp_capture_status' => 'CONFIRMED']);
        }

        if (Schema::hasTable('nutrition_profiles') && !Schema::hasColumn('nutrition_profiles', 'mobile_wizard_answers')) {
            Schema::table('nutrition_profiles', function (Blueprint $table): void {
                $table->json('mobile_wizard_answers')->nullable()->after('schedule_preferences');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('nutrition_profiles') && Schema::hasColumn('nutrition_profiles', 'mobile_wizard_answers')) {
            Schema::table('nutrition_profiles', fn (Blueprint $table) => $table->dropColumn('mobile_wizard_answers'));
        }

        if (Schema::hasTable('campaign_contacts')) {
            Schema::table('campaign_contacts', function (Blueprint $table): void {
                foreach ([
                    'whatsapp_capture_token',
                    'whatsapp_capture_status',
                    'whatsapp_capture_expires_at',
                    'whatsapp_qr_shown_at',
                    'whatsapp_vcard_downloaded_at',
                    'whatsapp_opened_at',
                    'whatsapp_confirmed_at',
                ] as $column) {
                    if (Schema::hasColumn('campaign_contacts', $column)) $table->dropColumn($column);
                }
            });
        }

        if (Schema::hasTable('contacts')) {
            Schema::table('contacts', function (Blueprint $table): void {
                if (Schema::hasColumn('contacts', 'whatsapp_verified_at')) $table->dropColumn('whatsapp_verified_at');
                if (Schema::hasColumn('contacts', 'whatsapp_status')) $table->dropColumn('whatsapp_status');
            });
        }
    }
};
