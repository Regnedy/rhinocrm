<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nutrition_profiles')) {
            if (!Schema::hasColumn('nutrition_profiles', 'occupation_schedule')) {
                Schema::table('nutrition_profiles', function (Blueprint $table): void {
                    $table->json('occupation_schedule')->nullable()->after('schedule_preferences');
                });
            }
            if (!Schema::hasColumn('nutrition_profiles', 'kitchen_resources')) {
                Schema::table('nutrition_profiles', function (Blueprint $table): void {
                    $table->json('kitchen_resources')->nullable()->after('occupation_schedule');
                });
            }
            if (!Schema::hasColumn('nutrition_profiles', 'food_access_notes')) {
                Schema::table('nutrition_profiles', function (Blueprint $table): void {
                    $table->string('food_access_notes', 1000)->nullable()->after('kitchen_resources');
                });
            }
            if (!Schema::hasColumn('nutrition_profiles', 'adherence_score')) {
                Schema::table('nutrition_profiles', function (Blueprint $table): void {
                    $table->unsignedTinyInteger('adherence_score')->nullable()->after('food_access_notes');
                });
            }
            if (!Schema::hasColumn('nutrition_profiles', 'last_reviewed_at')) {
                Schema::table('nutrition_profiles', function (Blueprint $table): void {
                    $table->dateTime('last_reviewed_at')->nullable()->index()->after('completed_at');
                });
            }
        }

        if (!Schema::hasTable('nutrition_pills')) {
            Schema::create('nutrition_pills', function (Blueprint $table): void {
                $table->string('id', 100)->primary();
                $table->string('scope_key', 80)->index();
                $table->string('partner_id', 64)->nullable()->index();
                $table->string('code', 80);
                $table->string('meal_type', 24)->index();
                $table->string('name', 180);
                $table->string('emoji', 16)->nullable();
                $table->text('description')->nullable();
                $table->string('portion_text', 500)->nullable();
                $table->json('ingredients');
                $table->text('preparation')->nullable();
                $table->json('food_groups')->nullable();
                $table->json('allergens')->nullable();
                $table->json('tags')->nullable();
                $table->json('compatible_goals')->nullable();
                $table->json('dietary_patterns')->nullable();
                $table->json('excluded_flags')->nullable();
                $table->string('budget_level', 24)->nullable()->index();
                $table->unsignedSmallInteger('prep_minutes')->nullable();
                $table->decimal('kcal_est', 8, 2)->nullable();
                $table->decimal('protein_g', 8, 2)->nullable();
                $table->decimal('carbs_g', 8, 2)->nullable();
                $table->decimal('fat_g', 8, 2)->nullable();
                $table->decimal('fiber_g', 8, 2)->nullable();
                $table->decimal('sodium_mg', 10, 2)->nullable();
                $table->string('status', 32)->default('DRAFT')->index();
                $table->string('source', 40)->default('MANUAL')->index();
                $table->string('source_reference', 180)->nullable();
                $table->unsignedInteger('current_version')->default(1);
                $table->string('created_by_user_id', 128)->nullable()->index();
                $table->string('approved_by_user_id', 128)->nullable()->index();
                $table->dateTime('approved_at')->nullable()->index();
                $table->timestamps();
                $table->unique(['scope_key', 'code'], 'nutrition_pills_scope_code_unique');
                $table->index(['scope_key', 'status', 'meal_type'], 'nutrition_pills_scope_status_meal_idx');
            });
        }

        if (!Schema::hasTable('nutrition_pill_versions')) {
            Schema::create('nutrition_pill_versions', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('pill_id', 100)->index();
                $table->unsignedInteger('version_no');
                $table->json('snapshot');
                $table->string('source', 40)->default('MANUAL')->index();
                $table->string('created_by_user_id', 128)->nullable()->index();
                $table->string('change_note', 1000)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->unique(['pill_id', 'version_no'], 'nutrition_pill_versions_unique');
            });
        }

        if (!Schema::hasTable('nutrition_templates')) {
            Schema::create('nutrition_templates', function (Blueprint $table): void {
                $table->string('id', 100)->primary();
                $table->string('scope_key', 80)->index();
                $table->string('partner_id', 64)->nullable()->index();
                $table->string('code', 80);
                $table->string('name', 180);
                $table->text('description')->nullable();
                $table->string('status', 32)->default('DRAFT')->index();
                $table->json('compatible_goals')->nullable();
                $table->json('dietary_patterns')->nullable();
                $table->json('rules');
                $table->unsignedInteger('current_version')->default(1);
                $table->string('created_by_user_id', 128)->nullable()->index();
                $table->string('approved_by_user_id', 128)->nullable()->index();
                $table->dateTime('approved_at')->nullable();
                $table->timestamps();
                $table->unique(['scope_key', 'code'], 'nutrition_templates_scope_code_unique');
                $table->index(['scope_key', 'status'], 'nutrition_templates_scope_status_idx');
            });
        }

        if (!Schema::hasTable('nutrition_engine_runs')) {
            Schema::create('nutrition_engine_runs', function (Blueprint $table): void {
                $table->string('id', 100)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('contact_id', 64)->index();
                $table->string('measurement_id', 100)->nullable()->index();
                $table->string('template_id', 100)->nullable()->index();
                $table->string('previous_plan_id', 100)->nullable()->index();
                $table->string('status', 32)->default('PROCESSING')->index();
                $table->string('safety_status', 48)->index();
                $table->string('algorithm_version', 80)->index();
                $table->json('input_snapshot');
                $table->json('decision_log')->nullable();
                $table->text('last_error')->nullable();
                $table->dateTime('generated_at')->nullable()->index();
                $table->timestamps();
                $table->index(['partner_id', 'contact_id', 'created_at'], 'nutrition_runs_contact_time_idx');
            });
        }

        if (Schema::hasTable('nutrition_plan_versions')) {
            if (!Schema::hasColumn('nutrition_plan_versions', 'source_type')) {
                Schema::table('nutrition_plan_versions', function (Blueprint $table): void {
                    $table->string('source_type', 32)->default('REPORT')->index()->after('personal_report_id');
                });
            }
            if (!Schema::hasColumn('nutrition_plan_versions', 'template_id')) {
                Schema::table('nutrition_plan_versions', function (Blueprint $table): void {
                    $table->string('template_id', 100)->nullable()->index()->after('source_type');
                });
            }
            if (!Schema::hasColumn('nutrition_plan_versions', 'engine_run_id')) {
                Schema::table('nutrition_plan_versions', function (Blueprint $table): void {
                    $table->string('engine_run_id', 100)->nullable()->index()->after('template_id');
                });
            }
            if (!Schema::hasColumn('nutrition_plan_versions', 'previous_plan_id')) {
                Schema::table('nutrition_plan_versions', function (Blueprint $table): void {
                    $table->string('previous_plan_id', 100)->nullable()->index()->after('engine_run_id');
                });
            }
            if (!Schema::hasColumn('nutrition_plan_versions', 'approval_status')) {
                Schema::table('nutrition_plan_versions', function (Blueprint $table): void {
                    $table->string('approval_status', 32)->default('AUTO_APPROVED')->index()->after('status');
                });
            }
            if (!Schema::hasColumn('nutrition_plan_versions', 'approved_by_user_id')) {
                Schema::table('nutrition_plan_versions', function (Blueprint $table): void {
                    $table->string('approved_by_user_id', 128)->nullable()->index()->after('approval_status');
                });
            }
            if (!Schema::hasColumn('nutrition_plan_versions', 'approved_at')) {
                Schema::table('nutrition_plan_versions', function (Blueprint $table): void {
                    $table->dateTime('approved_at')->nullable()->index()->after('approved_by_user_id');
                });
            }
            if (!Schema::hasColumn('nutrition_plan_versions', 'delivery_status')) {
                Schema::table('nutrition_plan_versions', function (Blueprint $table): void {
                    $table->string('delivery_status', 32)->default('NOT_SENT')->index()->after('approved_at');
                });
            }
            if (!Schema::hasColumn('nutrition_plan_versions', 'delivered_at')) {
                Schema::table('nutrition_plan_versions', function (Blueprint $table): void {
                    $table->dateTime('delivered_at')->nullable()->index()->after('delivery_status');
                });
            }
            if (!Schema::hasColumn('nutrition_plan_versions', 'is_current')) {
                Schema::table('nutrition_plan_versions', function (Blueprint $table): void {
                    $table->boolean('is_current')->default(true)->index()->after('delivered_at');
                });
            }
            if (!Schema::hasColumn('nutrition_plan_versions', 'adjustment_reason')) {
                Schema::table('nutrition_plan_versions', function (Blueprint $table): void {
                    $table->string('adjustment_reason', 1000)->nullable()->after('is_current');
                });
            }
            if (!Schema::hasColumn('nutrition_plan_versions', 'source_measurement_count')) {
                Schema::table('nutrition_plan_versions', function (Blueprint $table): void {
                    $table->unsignedInteger('source_measurement_count')->default(1)->after('adjustment_reason');
                });
            }
        }

        if (!Schema::hasTable('nutrition_plan_items')) {
            Schema::create('nutrition_plan_items', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('plan_id', 100)->index();
                $table->unsignedTinyInteger('day_index');
                $table->string('day_label', 20);
                $table->string('meal_slot', 24);
                $table->string('pill_id', 100)->index();
                $table->unsignedInteger('pill_version')->default(1);
                $table->decimal('serving_multiplier', 5, 2)->default(1);
                $table->json('item_snapshot');
                $table->timestamps();
                $table->unique(['plan_id', 'day_index', 'meal_slot'], 'nutrition_plan_items_slot_unique');
                $table->index(['plan_id', 'pill_id'], 'nutrition_plan_items_plan_pill_idx');
            });
        }

        if (!Schema::hasTable('nutrition_ai_batches')) {
            Schema::create('nutrition_ai_batches', function (Blueprint $table): void {
                $table->string('id', 100)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('requested_by_user_id', 128)->index();
                $table->string('title', 180);
                $table->string('status', 32)->default('PROMPT_READY')->index();
                $table->string('provider', 40)->default('MANUAL_JSON')->index();
                $table->string('model', 100)->nullable();
                $table->unsignedSmallInteger('requested_count')->default(10);
                $table->json('criteria');
                $table->longText('prompt_text');
                $table->longText('raw_response')->nullable();
                $table->json('validation_summary')->nullable();
                $table->text('last_error')->nullable();
                $table->dateTime('processed_at')->nullable()->index();
                $table->timestamps();
                $table->index(['partner_id', 'created_at'], 'nutrition_ai_batches_partner_time_idx');
            });
        }

        if (!Schema::hasTable('nutrition_ai_proposals')) {
            Schema::create('nutrition_ai_proposals', function (Blueprint $table): void {
                $table->string('id', 100)->primary();
                $table->string('batch_id', 100)->index();
                $table->string('partner_id', 64)->index();
                $table->string('status', 32)->default('DRAFT')->index();
                $table->string('proposed_code', 80)->nullable();
                $table->json('payload');
                $table->json('validation_errors')->nullable();
                $table->string('pill_id', 100)->nullable()->index();
                $table->string('reviewed_by_user_id', 128)->nullable()->index();
                $table->dateTime('reviewed_at')->nullable();
                $table->timestamps();
                $table->index(['batch_id', 'status'], 'nutrition_ai_proposals_batch_status_idx');
            });
        }

        if (!Schema::hasTable('nutrition_plan_feedback')) {
            Schema::create('nutrition_plan_feedback', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('plan_id', 100)->index();
                $table->string('partner_id', 64)->index();
                $table->string('contact_id', 64)->index();
                $table->unsignedTinyInteger('adherence_rating')->nullable();
                $table->json('favorite_pill_ids')->nullable();
                $table->json('rejected_pill_ids')->nullable();
                $table->text('notes')->nullable();
                $table->string('created_by_user_id', 128)->nullable()->index();
                $table->timestamps();
                $table->index(['partner_id', 'contact_id', 'created_at'], 'nutrition_feedback_contact_time_idx');
            });
        }

        $this->appendNutritionPermissions();
    }

    private function appendNutritionPermissions(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'permissions')) {
            return;
        }

        $byRole = [
            'PARTNER_ADMIN' => [
                'nutrition.read', 'nutrition.profiles.update', 'nutrition.plans.generate',
                'nutrition.plans.update', 'nutrition.pills.read', 'nutrition.pills.manage',
                'nutrition.ai.manage',
            ],
            'LOCATION_MANAGER' => [
                'nutrition.read', 'nutrition.profiles.update', 'nutrition.plans.generate',
                'nutrition.plans.update', 'nutrition.pills.read',
            ],
            'TEAM_LEADER' => [
                'nutrition.read', 'nutrition.profiles.update', 'nutrition.plans.generate',
                'nutrition.plans.update', 'nutrition.pills.read',
            ],
            'ADVISOR' => [
                'nutrition.read', 'nutrition.profiles.update', 'nutrition.plans.generate',
                'nutrition.plans.update', 'nutrition.pills.read',
            ],
        ];

        DB::table('users')
            ->whereIn('role', array_keys($byRole))
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($byRole): void {
                foreach ($users as $user) {
                    $stored = json_decode((string) ($user->permissions ?? '[]'), true);
                    $permissions = is_array($stored) ? array_values(array_filter(array_map('strval', $stored))) : [];
                    // Un arreglo vacío usa los permisos por defecto del rol en tiempo de ejecución.
                    // No lo conviertas en un arreglo explícito que contenga únicamente permisos de nutrición.
                    if ($permissions === []) {
                        continue;
                    }
                    $merged = array_values(array_unique(array_merge($permissions, $byRole[(string) $user->role] ?? [])));
                    DB::table('users')->where('id', $user->id)->update([
                        'permissions' => json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => now(),
                    ]);
                }
            }, 'id');
    }

    public function down(): void
    {
        // Política Rhino: no se eliminan columnas, tablas ni datos automáticamente.
        // Una reversión destructiva requiere respaldo y autorización explícita.
    }
};
