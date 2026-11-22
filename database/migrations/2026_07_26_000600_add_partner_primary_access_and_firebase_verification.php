<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (!Schema::hasColumn('users', 'is_primary_partner_admin')) {
                $table->boolean('is_primary_partner_admin')->default(false)->after('role')->index();
            }
            if (!Schema::hasColumn('users', 'firebase_email_verified_at')) {
                $table->timestamp('firebase_email_verified_at')->nullable()->after('firebase_uid');
            }
            if (!Schema::hasColumn('users', 'firebase_verification_sent_at')) {
                $table->timestamp('firebase_verification_sent_at')->nullable()->after('firebase_email_verified_at');
            }
            if (!Schema::hasColumn('users', 'firebase_verification_last_error')) {
                $table->string('firebase_verification_last_error', 1000)->nullable()->after('firebase_verification_sent_at');
            }
        });

        $partnerIds = DB::table('users')
            ->where('role', 'PARTNER_ADMIN')
            ->whereNotNull('partner_id')
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('partner_id');

        foreach ($partnerIds as $partnerId) {
            $primaryId = DB::table('users')
                ->where('partner_id', $partnerId)
                ->where('role', 'PARTNER_ADMIN')
                ->whereNull('deleted_at')
                ->orderByRaw("CASE WHEN status = 'ACTIVE' THEN 0 ELSE 1 END")
                ->orderBy('created_at')
                ->value('id');

            if ($primaryId !== null) {
                DB::table('users')
                    ->where('partner_id', $partnerId)
                    ->where('role', 'PARTNER_ADMIN')
                    ->update(['is_primary_partner_admin' => false]);

                DB::table('users')
                    ->where('id', $primaryId)
                    ->update(['is_primary_partner_admin' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            foreach ([
                'firebase_verification_last_error',
                'firebase_verification_sent_at',
                'firebase_email_verified_at',
                'is_primary_partner_admin',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
