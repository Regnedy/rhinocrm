<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createBillingPlans();
        $this->createBillingAccounts();
        $this->createBillingProviderProducts();
        $this->createBillingSubscriptions();
        $this->createBillingAccessGrants();
        $this->createBillingWebhookEvents();
        $this->createMobileAppDevices();
        $this->seedDraftPlan();
    }

    public function down(): void
    {
        // No destructivo: la estructura queda disponible para activar cobros posteriormente.
    }

    private function createBillingPlans(): void
    {
        if (Schema::hasTable('billing_plans')) return;

        Schema::create('billing_plans', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('code', 100)->unique();
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->string('interval_unit', 20)->default('MONTH');
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->unsignedInteger('included_seats')->default(1);
            $table->string('status', 24)->default('DRAFT')->index();
            $table->json('entitlements')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function createBillingAccounts(): void
    {
        if (Schema::hasTable('billing_accounts')) return;

        Schema::create('billing_accounts', function (Blueprint $table): void {
            $table->string('id', 80)->primary();
            $table->string('partner_id', 64)->unique();
            $table->string('status', 24)->default('ACTIVE')->index();
            $table->string('billing_email', 255)->nullable();
            $table->string('country_code', 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function createBillingProviderProducts(): void
    {
        if (Schema::hasTable('billing_provider_products')) return;

        Schema::create('billing_provider_products', function (Blueprint $table): void {
            $table->string('id', 80)->primary();
            $table->string('plan_id', 64)->index();
            $table->string('provider', 32)->index();
            $table->string('product_id', 180)->nullable();
            $table->string('base_plan_id', 180)->nullable();
            $table->string('offer_id', 180)->nullable();
            $table->string('status', 24)->default('DRAFT')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'provider'], 'billing_provider_plan_unique');
        });
    }

    private function createBillingSubscriptions(): void
    {
        if (Schema::hasTable('billing_subscriptions')) return;

        Schema::create('billing_subscriptions', function (Blueprint $table): void {
            $table->string('id', 80)->primary();
            $table->string('billing_account_id', 80)->index();
            $table->string('plan_id', 64)->index();
            $table->string('provider', 32)->default('MANUAL')->index();
            $table->string('provider_customer_id', 180)->nullable()->index();
            $table->string('provider_subscription_id', 255)->nullable()->index();
            $table->string('status', 40)->default('DRAFT')->index();
            $table->unsignedInteger('seat_quantity')->default(1);
            $table->dateTime('current_period_start')->nullable();
            $table->dateTime('current_period_end')->nullable()->index();
            $table->dateTime('trial_ends_at')->nullable();
            $table->dateTime('grace_ends_at')->nullable();
            $table->dateTime('cancel_at')->nullable();
            $table->dateTime('canceled_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->json('provider_payload')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_subscription_id'], 'billing_provider_subscription_unique');
        });
    }

    private function createBillingAccessGrants(): void
    {
        if (Schema::hasTable('billing_access_grants')) return;

        Schema::create('billing_access_grants', function (Blueprint $table): void {
            $table->string('id', 80)->primary();
            $table->string('partner_id', 64)->nullable()->index();
            $table->string('user_id', 128)->nullable()->index();
            $table->string('plan_id', 64)->index();
            $table->string('status', 24)->default('ACTIVE')->index();
            $table->string('reason', 500)->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable()->index();
            $table->string('granted_by_user_id', 128)->nullable()->index();
            $table->dateTime('revoked_at')->nullable();
            $table->string('revoked_by_user_id', 128)->nullable();
            $table->timestamps();
            $table->index(['partner_id', 'user_id', 'status'], 'billing_grant_scope_idx');
        });
    }

    private function createBillingWebhookEvents(): void
    {
        if (Schema::hasTable('billing_webhook_events')) return;

        Schema::create('billing_webhook_events', function (Blueprint $table): void {
            $table->string('id', 100)->primary();
            $table->string('provider', 32)->index();
            $table->string('external_event_id', 255)->nullable();
            $table->string('event_type', 160)->nullable()->index();
            $table->string('status', 24)->default('RECEIVED')->index();
            $table->json('payload')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamp('received_at')->useCurrent()->index();
            $table->dateTime('processed_at')->nullable();
            $table->unique(['provider', 'external_event_id'], 'billing_provider_event_unique');
        });
    }

    private function createMobileAppDevices(): void
    {
        if (Schema::hasTable('mobile_app_devices')) return;

        Schema::create('mobile_app_devices', function (Blueprint $table): void {
            $table->string('id', 80)->primary();
            $table->string('installation_id', 180)->unique();
            $table->string('user_id', 128)->index();
            $table->string('partner_id', 64)->nullable()->index();
            $table->string('platform', 24)->default('ANDROID')->index();
            $table->string('app_version', 40)->nullable();
            $table->string('device_name', 180)->nullable();
            $table->string('device_model', 180)->nullable();
            $table->text('push_token')->nullable();
            $table->string('push_provider', 32)->nullable();
            $table->string('status', 24)->default('ACTIVE')->index();
            $table->dateTime('last_seen_at')->nullable()->index();
            $table->json('capabilities')->nullable();
            $table->timestamps();
        });
    }

    private function seedDraftPlan(): void
    {
        if (!Schema::hasTable('billing_plans')) return;

        DB::table('billing_plans')->updateOrInsert(
            ['code' => 'rhino_crm_access'],
            [
                'id' => 'PLAN_RHINO_CRM_ACCESS',
                'name' => 'Rhino CRM Access',
                'description' => 'Acceso mensual a Rhino CRM web y aplicación móvil.',
                'amount_minor' => 999,
                'currency' => 'USD',
                'interval_unit' => 'MONTH',
                'interval_count' => 1,
                'included_seats' => 1,
                'status' => 'DRAFT',
                'entitlements' => json_encode([
                    'rhino.crm.access',
                    'mobile.app.access',
                    'contacts.access',
                    'events.access',
                    'nutrition.access',
                    'followup.access',
                    'sales.access',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'metadata' => json_encode([
                    'billing_enabled' => false,
                    'launch_price_display' => '9.99 USD',
                    'notes' => 'Producto preparado; cobros y exigencia de suscripción permanecen desactivados.',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        if (!Schema::hasTable('billing_provider_products')) return;

        foreach ([
            ['PROV_STRIPE_RHINO', 'STRIPE', 'rhino_crm_access', null],
            ['PROV_GOOGLE_RHINO', 'GOOGLE_PLAY', 'rhino_crm_access', 'monthly-auto'],
            ['PROV_APPLE_RHINO', 'APPLE', 'rhino_crm_access', null],
        ] as [$id, $provider, $productId, $basePlanId]) {
            DB::table('billing_provider_products')->updateOrInsert(
                ['plan_id' => 'PLAN_RHINO_CRM_ACCESS', 'provider' => $provider],
                [
                    'id' => $id,
                    'product_id' => $productId,
                    'base_plan_id' => $basePlanId,
                    'offer_id' => null,
                    'status' => 'DRAFT',
                    'metadata' => json_encode(['enabled' => false], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }
};
