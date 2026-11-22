<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->createCatalogAndTenancy();
        $this->createIdentityAndSecurity();
        $this->createTeams();
        $this->createCrmAndIot();
        $this->createCommerce();
        $this->createAutomationAndMessaging();
        $this->createOperationsAndAnalytics();
    }

    private function createCatalogAndTenancy(): void
    {
        if (!Schema::hasTable('plans')) {
            Schema::create('plans', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('name', 120);
                $table->unsignedInteger('max_users')->default(5);
                $table->unsignedInteger('max_devices')->default(3);
                $table->unsignedBigInteger('max_measurements')->default(5000);
                $table->boolean('active')->default(true)->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('partners')) {
            Schema::create('partners', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('plan_id', 64)->index();
                $table->string('business_name', 180);
                $table->string('country', 80)->default('MX');
                $table->string('country_dial_code', 8)->default('52');
                $table->string('status', 32)->default('ACTIVE')->index();
                $table->string('suspended_reason', 500)->nullable();
                $table->timestamp('suspended_at')->nullable();
                $table->string('suspended_by', 128)->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('locations')) {
            Schema::create('locations', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('name', 160);
                $table->string('city', 120)->nullable();
                $table->string('state', 120)->nullable();
                $table->string('status', 32)->default('ACTIVE')->index();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['partner_id', 'status']);
            });
        }

        if (!Schema::hasTable('system_settings')) {
            Schema::create('system_settings', function (Blueprint $table): void {
                $table->string('key', 120)->primary();
                $table->longText('value')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('feature_flags')) {
            Schema::create('feature_flags', function (Blueprint $table): void {
                $table->id();
                $table->string('partner_id', 64)->nullable()->index();
                $table->string('feature', 120)->index();
                $table->boolean('enabled')->default(false);
                $table->json('configuration')->nullable();
                $table->timestamps();
                $table->unique(['partner_id', 'feature'], 'feature_flags_partner_feature_unique');
            });
        }
    }

    private function createIdentityAndSecurity(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->string('id', 128)->primary();
                $table->string('partner_id', 64)->nullable()->index();
                $table->string('location_id', 64)->nullable()->index();
                $table->string('role', 40)->index();
                $table->string('name', 160);
                $table->string('email', 255)->unique();
                $table->string('password_hash')->nullable();
                $table->string('auth_provider', 32)->default('LOCAL');
                $table->string('firebase_uid', 128)->nullable()->unique();
                $table->json('permissions')->nullable();
                $table->string('status', 32)->default('ACTIVE')->index();
                $table->timestamp('last_login_at')->nullable();
                $table->timestamp('suspended_at')->nullable();
                $table->string('suspended_by', 128)->nullable();
                $table->string('suspension_reason', 500)->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['partner_id', 'role', 'status']);
            });
        }

        if (!Schema::hasTable('api_sessions')) {
            Schema::create('api_sessions', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('user_id', 128)->index();
                $table->string('partner_id', 64)->nullable()->index();
                $table->string('token_hash', 64)->unique();
                $table->string('device_name', 180)->nullable();
                $table->string('device_fingerprint_hash', 64)->nullable()->index();
                $table->string('platform', 32)->default('ANDROID');
                $table->string('app_version', 40)->nullable();
                $table->text('fcm_token')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->dateTime('expires_at')->index();
                $table->timestamp('revoked_at')->nullable()->index();
                $table->string('revoked_by', 128)->nullable();
                $table->string('revocation_reason', 500)->nullable();
                $table->timestamps();
                $table->index(['user_id', 'revoked_at', 'expires_at'], 'api_sessions_user_state_idx');
            });
        }

        if (!Schema::hasTable('security_audit_logs')) {
            Schema::create('security_audit_logs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('actor_user_id', 128)->nullable()->index();
                $table->string('partner_id', 64)->nullable()->index();
                $table->string('target_type', 80)->index();
                $table->string('target_id', 128)->nullable()->index();
                $table->string('action', 120)->index();
                $table->string('reason', 1000)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }
    }

    private function createTeams(): void
    {
        if (!Schema::hasTable('teams')) {
            Schema::create('teams', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('location_id', 64)->nullable()->index();
                $table->string('name', 160);
                $table->string('leader_user_id', 128)->nullable()->index();
                $table->string('status', 32)->default('ACTIVE')->index();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['partner_id', 'name'], 'teams_partner_name_unique');
            });
        }

        if (!Schema::hasTable('team_members')) {
            Schema::create('team_members', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('team_id', 64)->index();
                $table->string('partner_id', 64)->index();
                $table->string('user_id', 128)->index();
                $table->string('member_role', 32)->default('MEMBER');
                $table->string('status', 32)->default('ACTIVE')->index();
                $table->timestamp('joined_at')->useCurrent();
                $table->timestamp('left_at')->nullable();
                $table->timestamps();
                $table->unique(['team_id', 'user_id'], 'team_members_team_user_unique');
                $table->index(['partner_id', 'user_id', 'status']);
            });
        }

        if (!Schema::hasTable('team_goals')) {
            Schema::create('team_goals', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('team_id', 64)->nullable()->index();
                $table->string('user_id', 128)->nullable()->index();
                $table->string('period', 7)->index();
                $table->unsignedInteger('prospects_target')->default(0);
                $table->unsignedInteger('measurements_target')->default(0);
                $table->unsignedInteger('sales_target')->default(0);
                $table->unsignedBigInteger('revenue_target_minor')->default(0);
                $table->string('currency', 3)->default('MXN');
                $table->timestamps();
                $table->unique(['partner_id', 'team_id', 'user_id', 'period'], 'team_goals_scope_period_unique');
            });
        }
    }

    private function createCrmAndIot(): void
    {
        if (!Schema::hasTable('devices')) {
            Schema::create('devices', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('mac_address', 17)->unique();
                $table->string('partner_id', 64)->index();
                $table->string('location_id', 64)->index();
                $table->longText('device_token');
                $table->string('status', 32)->default('ACTIVE')->index();
                $table->unsignedBigInteger('total_measurements')->default(0);
                $table->timestamp('last_measurement_at')->nullable()->index();
                $table->string('linked_by_user_id', 128)->nullable();
                $table->timestamp('suspended_at')->nullable();
                $table->string('suspended_by', 128)->nullable();
                $table->string('suspension_reason', 500)->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['partner_id', 'status']);
            });
        }

        if (!Schema::hasTable('contacts')) {
            Schema::create('contacts', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('whatsapp', 40);
                $table->string('whatsapp_normalized', 40)->index();
                $table->string('name', 180);
                $table->unsignedSmallInteger('age')->nullable();
                $table->string('gender', 32)->nullable();
                $table->decimal('height_cm', 6, 2)->nullable();
                $table->string('profile', 80)->nullable();
                $table->string('lifecycle_stage', 32)->default('CONTACT')->index();
                $table->string('sales_stage', 32)->default('NEW')->index();
                $table->string('status', 32)->default('ACTIVE')->index();
                $table->json('contact_source')->nullable();
                $table->string('generated_by_user_id', 128)->nullable()->index();
                $table->string('origin_team_id', 64)->nullable()->index();
                $table->string('assigned_advisor_id', 128)->nullable()->index();
                $table->string('loss_reason', 500)->nullable();
                $table->json('metrics_summary')->nullable();
                $table->unsignedInteger('total_measurements')->default(0);
                $table->timestamp('last_measurement_at')->nullable()->index();
                $table->timestamp('blocked_at')->nullable();
                $table->string('blocked_by', 128)->nullable();
                $table->string('block_reason', 500)->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['partner_id', 'whatsapp_normalized'], 'contacts_partner_phone_unique');
                $table->index(['partner_id', 'sales_stage', 'assigned_advisor_id'], 'contacts_pipeline_idx');
            });
        }

        if (!Schema::hasTable('measurements')) {
            Schema::create('measurements', function (Blueprint $table): void {
                $table->string('id', 100)->primary();
                $table->string('device_id', 64)->index();
                $table->string('partner_id', 64)->index();
                $table->string('location_id', 64)->index();
                $table->string('contact_id', 64)->nullable()->index();
                $table->json('biometrics_raw');
                $table->json('biometrics_calculated');
                $table->dateTime('timestamp_local')->index();
                $table->timestamp('received_at')->useCurrent();
                $table->index(['partner_id', 'contact_id', 'timestamp_local'], 'measurements_contact_time_idx');
            });
        }

        if (!Schema::hasTable('measurement_intakes')) {
            Schema::create('measurement_intakes', function (Blueprint $table): void {
                $table->string('measurement_id', 100)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('status', 32)->default('PENDING')->index();
                $table->longText('patient_payload_encrypted')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('processed_at')->nullable();
            });
        }

        if (!Schema::hasTable('partner_usage')) {
            Schema::create('partner_usage', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('partner_id', 64)->index();
                $table->string('period', 7)->index();
                $table->unsignedBigInteger('measurement_count')->default(0);
                $table->timestamps();
                $table->unique(['partner_id', 'period']);
            });
        }

        if (!Schema::hasTable('tasks')) {
            Schema::create('tasks', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('contact_id', 64)->index();
                $table->string('assigned_user_id', 128)->index();
                $table->string('team_id', 64)->nullable()->index();
                $table->string('type', 60);
                $table->string('title', 255);
                $table->string('status', 32)->default('PENDING')->index();
                $table->dateTime('due_at')->index();
                $table->string('outcome', 500)->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('canceled_at')->nullable();
                $table->timestamps();
                $table->index(['partner_id', 'assigned_user_id', 'status', 'due_at'], 'tasks_assignee_state_due_idx');
            });
        }

        if (!Schema::hasTable('timeline_activities')) {
            Schema::create('timeline_activities', function (Blueprint $table): void {
                $table->string('id', 100)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('contact_id', 64)->index();
                $table->string('type', 80)->index();
                $table->text('description');
                $table->json('metadata')->nullable();
                $table->string('actor_id', 128)->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
                $table->index(['partner_id', 'contact_id', 'created_at'], 'timeline_contact_time_idx');
            });
        }

        if (!Schema::hasTable('sla_alerts')) {
            Schema::create('sla_alerts', function (Blueprint $table): void {
                $table->string('task_id', 64)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('contact_id', 64)->index();
                $table->string('assigned_user_id', 128)->index();
                $table->string('status', 32)->default('OPEN')->index();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('resolved_at')->nullable();
            });
        }
    }

    private function createCommerce(): void
    {
        if (!Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('sku', 100);
                $table->string('name', 180);
                $table->string('category', 120)->nullable();
                $table->unsignedBigInteger('base_price_minor');
                $table->unsignedBigInteger('base_cost_minor')->default(0);
                $table->string('currency', 3)->default('MXN');
                $table->boolean('active')->default(true)->index();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['partner_id', 'sku']);
            });
        }

        if (!Schema::hasTable('inventory_balances')) {
            Schema::create('inventory_balances', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('partner_id', 64)->index();
                $table->string('location_id', 64)->index();
                $table->string('product_id', 64)->index();
                $table->integer('quantity')->default(0);
                $table->timestamps();
                $table->unique(['partner_id', 'location_id', 'product_id'], 'inventory_balance_scope_unique');
            });
        }

        if (!Schema::hasTable('inventory_transactions')) {
            Schema::create('inventory_transactions', function (Blueprint $table): void {
                $table->string('id', 100)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('location_id', 64)->index();
                $table->string('product_id', 64)->index();
                $table->string('transaction_type', 60)->index();
                $table->integer('quantity_change');
                $table->string('reference_id', 100)->nullable()->index();
                $table->string('reason', 500)->nullable();
                $table->string('actor_id', 128)->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }

        if (!Schema::hasTable('sales')) {
            Schema::create('sales', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('contact_id', 64)->index();
                $table->string('advisor_id', 128)->index();
                $table->string('team_id', 64)->nullable()->index();
                $table->string('location_id', 64)->index();
                $table->string('sale_type', 40)->index();
                $table->string('status', 32)->default('COMPLETED')->index();
                $table->string('payment_method', 60);
                $table->unsignedBigInteger('total_revenue_minor');
                $table->bigInteger('total_profit_minor');
                $table->string('currency_snapshot', 3)->default('MXN');
                $table->string('origin_device_id', 64)->nullable()->index();
                $table->string('idempotency_key', 255);
                $table->string('refund_reason', 500)->nullable();
                $table->dateTime('closed_at')->index();
                $table->timestamp('refunded_at')->nullable();
                $table->timestamps();
                $table->unique(['partner_id', 'idempotency_key'], 'sales_partner_idempotency_unique');
            });
        }

        if (!Schema::hasTable('sale_items')) {
            Schema::create('sale_items', function (Blueprint $table): void {
                $table->string('id', 80)->primary();
                $table->string('sale_id', 64)->index();
                $table->string('partner_id', 64)->index();
                $table->string('product_id', 64)->index();
                $table->unsignedInteger('quantity');
                $table->string('product_name_snapshot', 180);
                $table->unsignedBigInteger('unit_price_minor_snapshot');
                $table->unsignedBigInteger('unit_cost_minor_snapshot');
                $table->unsignedBigInteger('subtotal_revenue_minor');
                $table->bigInteger('subtotal_profit_minor');
            });
        }

        if (!Schema::hasTable('financial_transactions')) {
            Schema::create('financial_transactions', function (Blueprint $table): void {
                $table->string('id', 100)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('sale_id', 64)->index();
                $table->string('type', 60)->index();
                $table->bigInteger('amount_minor');
                $table->bigInteger('profit_minor');
                $table->string('currency', 3)->default('MXN');
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }
    }

    private function createAutomationAndMessaging(): void
    {
        if (!Schema::hasTable('whatsapp_messages')) {
            Schema::create('whatsapp_messages', function (Blueprint $table): void {
                $table->string('id', 100)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('contact_id', 64)->index();
                $table->string('to_phone_number', 40);
                $table->string('template_name', 160);
                $table->json('variables')->nullable();
                $table->string('status', 32)->index();
                $table->string('external_id', 255)->nullable()->unique();
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('workflow_definitions')) {
            Schema::create('workflow_definitions', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('name', 180);
                $table->unsignedInteger('version')->default(1);
                $table->string('trigger_event', 100)->index();
                $table->json('trigger_conditions')->nullable();
                $table->longText('nodes');
                $table->string('entry_node_id', 100);
                $table->boolean('cancel_on_stage_change')->default(false);
                $table->boolean('is_active')->default(false)->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('workflow_executions')) {
            Schema::create('workflow_executions', function (Blueprint $table): void {
                $table->string('id', 100)->primary();
                $table->string('workflow_id', 64)->index();
                $table->unsignedInteger('workflow_version');
                $table->string('partner_id', 64)->index();
                $table->string('contact_id', 64)->index();
                $table->string('trigger_event_id', 100)->index();
                $table->string('current_node_id', 100)->nullable();
                $table->string('status', 32)->index();
                $table->longText('nodes_snapshot');
                $table->longText('completed_nodes')->nullable();
                $table->timestamp('resume_at')->nullable()->index();
                $table->text('failure_reason')->nullable();
                $table->timestamps();
                $table->index(['partner_id', 'contact_id', 'status']);
            });
        }

        if (!Schema::hasTable('workflow_execution_logs')) {
            Schema::create('workflow_execution_logs', function (Blueprint $table): void {
                $table->string('id', 100)->primary();
                $table->string('execution_id', 100)->index();
                $table->string('partner_id', 64)->index();
                $table->string('node_id', 100);
                $table->string('action_type', 100);
                $table->string('status', 32)->index();
                $table->text('output_message')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }
    }

    private function createOperationsAndAnalytics(): void
    {
        if (!Schema::hasTable('event_outbox')) {
            Schema::create('event_outbox', function (Blueprint $table): void {
                $table->string('id', 100)->primary();
                $table->string('event_type', 100)->index();
                $table->unsignedInteger('event_version')->default(1);
                $table->string('partner_id', 64)->index();
                $table->longText('payload');
                $table->string('status', 32)->default('PENDING')->index();
                $table->unsignedInteger('attempt_count')->default(0);
                $table->dateTime('available_at')->index();
                $table->timestamp('locked_until')->nullable()->index();
                $table->string('lock_token', 100)->nullable()->index();
                $table->string('last_error', 1000)->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->timestamps();
                $table->index(['status', 'available_at', 'locked_until'], 'outbox_claim_idx');
            });
        }

        if (!Schema::hasTable('event_consumptions')) {
            Schema::create('event_consumptions', function (Blueprint $table): void {
                $table->string('consumer_key_hash', 64)->primary();
                $table->string('consumer', 120)->index();
                $table->string('consumer_key', 500);
                $table->string('event_id', 100)->index();
                $table->string('partner_id', 64)->index();
                $table->string('result', 120)->nullable();
                $table->timestamp('processed_at')->useCurrent();
            });
        }

        if (!Schema::hasTable('command_idempotency')) {
            Schema::create('command_idempotency', function (Blueprint $table): void {
                $table->string('key_hash', 64)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('type', 100)->index();
                $table->string('resource_id', 128)->nullable();
                $table->string('command_hash', 255)->nullable();
                $table->string('event_id', 100)->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (!Schema::hasTable('assignment_cursors')) {
            Schema::create('assignment_cursors', function (Blueprint $table): void {
                $table->string('scope_hash', 64)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('location_id', 64)->nullable()->index();
                $table->string('team_id', 64)->nullable()->index();
                $table->unsignedInteger('cursor')->default(0);
                $table->timestamp('updated_at')->useCurrent();
            });
        }

        if (!Schema::hasTable('crm_measurement_processing')) {
            Schema::create('crm_measurement_processing', function (Blueprint $table): void {
                $table->string('measurement_id', 100)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('contact_id', 64)->nullable()->index();
                $table->string('status', 32)->index();
                $table->boolean('is_new_contact')->default(false);
                $table->string('event_status', 32)->default('PENDING')->index();
                $table->string('event_id', 100)->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('report_exports')) {
            Schema::create('report_exports', function (Blueprint $table): void {
                $table->string('id', 80)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('requested_by', 128)->index();
                $table->string('source_table', 100);
                $table->json('columns');
                $table->string('status', 32)->default('PENDING')->index();
                $table->string('file_path', 1000)->nullable();
                $table->unsignedBigInteger('row_count')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }

        $this->createAnalyticsTable('partner_analytics', ['partner_id']);
        $this->createAnalyticsTable('user_analytics', ['partner_id', 'user_id']);
        $this->createAnalyticsTable('team_analytics', ['partner_id', 'team_id']);
        $this->createAnalyticsTable('device_analytics', ['partner_id', 'device_id']);
        $this->createAnalyticsTable('product_analytics', ['partner_id', 'product_id']);

        if (!Schema::hasTable('funnel_analytics')) {
            Schema::create('funnel_analytics', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('partner_id', 64)->index();
                $table->string('team_id', 64)->nullable()->index();
                $table->string('user_id', 128)->nullable()->index();
                $table->string('period', 7)->index();
                $table->json('stage_entries')->nullable();
                $table->unsignedInteger('prospects_generated')->default(0);
                $table->unsignedInteger('prospects_assigned')->default(0);
                $table->unsignedInteger('closed_leads')->default(0);
                $table->unsignedInteger('lost_leads')->default(0);
                $table->timestamps();
                $table->unique(['partner_id', 'team_id', 'user_id', 'period'], 'funnel_analytics_scope_period_unique');
            });
        }

        if (!Schema::hasTable('deployment_logs')) {
            Schema::create('deployment_logs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('version', 80);
                $table->string('status', 32)->index();
                $table->string('database_backup', 1000)->nullable();
                $table->json('migrations')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('deployed_at')->useCurrent()->index();
            });
        }
    }

    /** @param array<int, string> $dimensions */
    private function createAnalyticsTable(string $name, array $dimensions): void
    {
        if (Schema::hasTable($name)) {
            return;
        }

        Schema::create($name, function (Blueprint $table) use ($dimensions, $name): void {
            $table->bigIncrements('id');
            foreach ($dimensions as $dimension) {
                $length = $dimension === 'user_id' ? 128 : 64;
                $table->string($dimension, $length)->index();
            }
            $table->string('period', 7)->index();
            $table->string('currency', 3)->default('MXN');
            $table->unsignedInteger('prospects_generated')->default(0);
            $table->unsignedInteger('prospects_assigned')->default(0);
            $table->unsignedInteger('prospects_contacted')->default(0);
            $table->unsignedInteger('prospects_converted')->default(0);
            $table->unsignedInteger('sales_count')->default(0);
            $table->unsignedInteger('sales_closed')->default(0);
            $table->unsignedInteger('refund_count')->default(0);
            $table->unsignedBigInteger('total_revenue_minor')->default(0);
            $table->bigInteger('total_profit_minor')->default(0);
            $table->bigInteger('revenue_generated_minor')->default(0);
            $table->bigInteger('sales_amount_minor')->default(0);
            $table->bigInteger('profit_generated_minor')->default(0);
            $table->unsignedBigInteger('refunded_revenue_minor')->default(0);
            $table->bigInteger('refunded_profit_minor')->default(0);
            $table->unsignedInteger('acquisition_sales')->default(0);
            $table->unsignedInteger('recurring_sales')->default(0);
            $table->integer('units_sold')->default(0);
            $table->unsignedInteger('units_returned')->default(0);
            $table->integer('revenue_minor')->default(0);
            $table->integer('profit_minor')->default(0);
            $table->timestamps();
            $table->unique(array_merge($dimensions, ['period']), $name.'_scope_period_unique');
        });
    }

    public function down(): void
    {
        // Intencionalmente no se eliminan tablas. Rhino usa migraciones aditivas y
        // los rollbacks destructivos requieren una migración explícita y revisada.
    }
};
