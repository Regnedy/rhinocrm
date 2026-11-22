<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->prepareProducts();
        $this->prepareInventoryLedger();
        $this->prepareFinanceLedger();
        $this->prepareExistingSalesTables();
        $this->grantMobilePermissions();
        $this->seedFeatureFlags();
    }

    public function down(): void
    {
        // Migración deliberadamente no destructiva.
    }

    private function prepareProducts(): void
    {
        if (!Schema::hasTable('products')) return;

        Schema::table('products', function (Blueprint $table): void {
            if (!Schema::hasColumn('products', 'description')) $table->text('description')->nullable();
            if (!Schema::hasColumn('products', 'barcode')) $table->string('barcode', 120)->nullable()->index();
            if (!Schema::hasColumn('products', 'unit')) $table->string('unit', 32)->default('PIECE');
            if (!Schema::hasColumn('products', 'track_inventory')) $table->boolean('track_inventory')->default(false)->index();
            if (!Schema::hasColumn('products', 'min_stock')) $table->integer('min_stock')->default(0);
            if (!Schema::hasColumn('products', 'created_by_user_id')) $table->string('created_by_user_id', 128)->nullable()->index();
            if (!Schema::hasColumn('products', 'updated_by_user_id')) $table->string('updated_by_user_id', 128)->nullable()->index();
        });
    }

    private function prepareInventoryLedger(): void
    {
        if (!Schema::hasTable('inventory_accounts')) {
            Schema::create('inventory_accounts', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('account_type', 32)->index(); // GENERAL, ADVISOR, EVENT
                $table->string('account_key', 255); // GENERAL, ADVISOR:<uid>, EVENT:<campaign>
                $table->string('owner_user_id', 128)->nullable()->index();
                $table->string('campaign_id', 64)->nullable()->index();
                $table->string('name', 180);
                $table->string('status', 32)->default('ACTIVE')->index();
                $table->string('created_by_user_id', 128)->nullable();
                $table->timestamps();
                $table->unique(['partner_id', 'account_key'], 'inventory_accounts_partner_key_unique');
                $table->index(['partner_id', 'account_type', 'status'], 'inventory_accounts_scope_idx');
            });
        }

        if (!Schema::hasTable('inventory_ledger_entries')) {
            Schema::create('inventory_ledger_entries', function (Blueprint $table): void {
                $table->string('id', 100)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('account_id', 64)->index();
                $table->string('product_id', 64)->index();
                $table->string('movement_type', 60)->index();
                $table->integer('quantity_change');
                $table->integer('balance_after')->nullable();
                $table->string('reference_type', 60)->nullable()->index();
                $table->string('reference_id', 100)->nullable()->index();
                $table->string('sale_id', 64)->nullable()->index();
                $table->string('campaign_id', 64)->nullable()->index();
                $table->string('contact_id', 64)->nullable()->index();
                $table->string('actor_id', 128)->nullable()->index();
                $table->string('approval_status', 32)->default('APPROVED')->index();
                $table->string('approved_by', 128)->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->string('reason', 500)->nullable();
                $table->string('idempotency_key', 255);
                $table->json('metadata')->nullable();
                $table->dateTime('occurred_at')->index();
                $table->timestamp('created_at')->useCurrent()->index();
                $table->unique(['account_id', 'product_id', 'idempotency_key'], 'inventory_ledger_line_idempotency_unique');
                $table->index(['partner_id', 'product_id', 'occurred_at'], 'inventory_ledger_product_time_idx');
            });
        }
    }

    private function prepareFinanceLedger(): void
    {
        if (!Schema::hasTable('finance_ledger_entries')) {
            Schema::create('finance_ledger_entries', function (Blueprint $table): void {
                $table->string('id', 100)->primary();
                $table->string('partner_id', 64)->index();
                $table->string('entry_type', 60)->index();
                $table->string('direction', 16)->index(); // INFLOW, OUTFLOW
                $table->bigInteger('amount_minor');
                $table->string('currency', 3)->default('MXN');
                $table->string('reference_type', 60)->nullable()->index();
                $table->string('reference_id', 100)->nullable()->index();
                $table->string('sale_id', 64)->nullable()->index();
                $table->string('campaign_id', 64)->nullable()->index();
                $table->string('contact_id', 64)->nullable()->index();
                $table->string('advisor_id', 128)->nullable()->index();
                $table->string('payment_method', 60)->nullable()->index();
                $table->string('status', 32)->default('POSTED')->index();
                $table->string('idempotency_key', 255);
                $table->json('metadata')->nullable();
                $table->dateTime('occurred_at')->index();
                $table->timestamp('created_at')->useCurrent()->index();
                $table->unique(['partner_id', 'idempotency_key'], 'finance_ledger_partner_idempotency_unique');
                $table->index(['partner_id', 'campaign_id', 'occurred_at'], 'finance_ledger_campaign_time_idx');
            });
        }
    }

    private function prepareExistingSalesTables(): void
    {
        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table): void {
                if (!Schema::hasColumn('sales', 'source_channel')) $table->string('source_channel', 32)->nullable()->index();
                if (!Schema::hasColumn('sales', 'inventory_processed_at')) $table->timestamp('inventory_processed_at')->nullable()->index();
            });
        }
        if (Schema::hasTable('inventory_transactions')) {
            Schema::table('inventory_transactions', function (Blueprint $table): void {
                if (!Schema::hasColumn('inventory_transactions', 'account_id')) $table->string('account_id', 64)->nullable()->index();
                if (!Schema::hasColumn('inventory_transactions', 'campaign_id')) $table->string('campaign_id', 64)->nullable()->index();
                if (!Schema::hasColumn('inventory_transactions', 'sale_id')) $table->string('sale_id', 64)->nullable()->index();
                if (!Schema::hasColumn('inventory_transactions', 'contact_id')) $table->string('contact_id', 64)->nullable()->index();
                if (!Schema::hasColumn('inventory_transactions', 'balance_after')) $table->integer('balance_after')->nullable();
            });
        }
        if (Schema::hasTable('financial_transactions')) {
            Schema::table('financial_transactions', function (Blueprint $table): void {
                if (!Schema::hasColumn('financial_transactions', 'campaign_id')) $table->string('campaign_id', 64)->nullable()->index();
                if (!Schema::hasColumn('financial_transactions', 'contact_id')) $table->string('contact_id', 64)->nullable()->index();
                if (!Schema::hasColumn('financial_transactions', 'advisor_id')) $table->string('advisor_id', 128)->nullable()->index();
                if (!Schema::hasColumn('financial_transactions', 'payment_method')) $table->string('payment_method', 60)->nullable()->index();
                if (!Schema::hasColumn('financial_transactions', 'occurred_at')) $table->dateTime('occurred_at')->nullable()->index();
            });
        }
    }

    private function grantMobilePermissions(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'permissions')) return;

        $permissions = [
            'PARTNER_ADMIN' => ['mobile.context', 'mobile.measurements.create'],
            'LOCATION_MANAGER' => ['mobile.context', 'mobile.measurements.create'],
            'TEAM_LEADER' => ['mobile.context', 'mobile.measurements.create'],
            'ADVISOR' => ['mobile.context', 'mobile.measurements.create'],
            'OPERATOR' => ['mobile.context', 'mobile.measurements.create'],
        ];

        DB::table('users')->whereIn('role', array_keys($permissions))->orderBy('id')->chunk(200, function ($users) use ($permissions): void {
            foreach ($users as $user) {
                $current = json_decode((string) ($user->permissions ?? '[]'), true);
                $current = is_array($current) ? $current : [];
                $merged = array_values(array_unique(array_merge($current, $permissions[(string) $user->role] ?? [])));
                DB::table('users')->where('id', $user->id)->update([
                    'permissions' => json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    private function seedFeatureFlags(): void
    {
        if (!Schema::hasTable('feature_flags') || !Schema::hasTable('partners')) return;

        foreach ([
            'inventory_ledger_v2' => false,
            'finance_reports_v2' => false,
        ] as $feature => $enabled) {
            DB::table('feature_flags')->updateOrInsert(
                ['partner_id' => null, 'feature' => $feature],
                ['enabled' => $enabled, 'configuration' => null, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        foreach (DB::table('partners')->whereNull('deleted_at')->pluck('id') as $partnerId) {
            foreach ([
                'mobile_kiosk_api' => true,
                'inventory_ledger_v2' => false,
                'finance_reports_v2' => false,
            ] as $feature => $enabled) {
                DB::table('feature_flags')->updateOrInsert(
                    ['partner_id' => $partnerId, 'feature' => $feature],
                    ['enabled' => $enabled, 'configuration' => null, 'created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }
};
