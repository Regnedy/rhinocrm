<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nutrition_profiles')) {
            if (!Schema::hasColumn('nutrition_profiles', 'dietary_pattern')) {
                Schema::table('nutrition_profiles', function (Blueprint $table): void {
                    $table->string('dietary_pattern', 60)->nullable()->after('activity_level');
                });
            }
            if (!Schema::hasColumn('nutrition_profiles', 'budget_level')) {
                Schema::table('nutrition_profiles', function (Blueprint $table): void {
                    $table->string('budget_level', 40)->nullable()->after('meals_per_day');
                });
            }
            if (!Schema::hasColumn('nutrition_profiles', 'cooking_time_minutes')) {
                Schema::table('nutrition_profiles', function (Blueprint $table): void {
                    $table->unsignedSmallInteger('cooking_time_minutes')->nullable()->after('budget_level');
                });
            }
        }

        if (Schema::hasTable('personal_reports')) {
            if (!Schema::hasColumn('personal_reports', 'nutrition_plan_id')) {
                Schema::table('personal_reports', function (Blueprint $table): void {
                    $table->string('nutrition_plan_id', 100)->nullable()->index()->after('measurement_id');
                });
            }
            if (!Schema::hasColumn('personal_reports', 'nutrition_plan_status')) {
                Schema::table('personal_reports', function (Blueprint $table): void {
                    $table->string('nutrition_plan_status', 48)->nullable()->index()->after('algorithm_version');
                });
            }
            if (!Schema::hasColumn('personal_reports', 'nutrition_algorithm_version')) {
                Schema::table('personal_reports', function (Blueprint $table): void {
                    $table->string('nutrition_algorithm_version', 80)->nullable()->after('nutrition_plan_status');
                });
            }
            if (!Schema::hasColumn('personal_reports', 'nutrition_profile_snapshot')) {
                Schema::table('personal_reports', function (Blueprint $table): void {
                    $table->json('nutrition_profile_snapshot')->nullable()->after('nutrition_algorithm_version');
                });
            }
            if (!Schema::hasColumn('personal_reports', 'nutrition_plan_snapshot')) {
                Schema::table('personal_reports', function (Blueprint $table): void {
                    $table->json('nutrition_plan_snapshot')->nullable()->after('nutrition_profile_snapshot');
                });
            }
            if (!Schema::hasColumn('personal_reports', 'nutrition_generated_at')) {
                Schema::table('personal_reports', function (Blueprint $table): void {
                    $table->dateTime('nutrition_generated_at')->nullable()->index()->after('nutrition_plan_snapshot');
                });
            }
        }

        if (!Schema::hasTable('nutrition_plan_versions')) {
            Schema::create('nutrition_plan_versions', function (Blueprint $table): void {
                $table->string('id', 100)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('contact_id', 64)->index();
                $table->string('measurement_id', 100)->index();
                $table->string('personal_report_id', 100)->index();
                $table->string('status', 48)->index();
                $table->boolean('requires_professional_review')->default(false)->index();
                $table->string('algorithm_version', 80)->index();
                $table->json('profile_snapshot')->nullable();
                $table->json('plan_snapshot');
                $table->dateTime('generated_at')->index();
                $table->timestamps();
                $table->unique(
                    ['personal_report_id', 'algorithm_version'],
                    'nutrition_plan_versions_report_algorithm_unique'
                );
            });
        }
    }

    public function down(): void
    {
        // Política Rhino: no se eliminan columnas, tablas ni datos automáticamente.
        // Una reversión destructiva requiere respaldo y autorización explícita.
    }
};
