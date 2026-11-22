<?php

namespace App\Modules\Billing\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class BillingAccessService
{
    /** @return array<string,mixed> */
    public function access(UserContextDTO $context): array
    {
        $plan = $this->plan();
        $configuration = $this->configuration();

        if ($context->isSuperAdmin()) {
            return $this->allowed('super_admin', 'COMPLIMENTARY', $plan, $configuration, null);
        }

        if (!$configuration['enabled'] || !$configuration['enforcement_enabled']) {
            return $this->allowed('billing_disabled', 'DEVELOPMENT', $plan, $configuration, null);
        }

        $grant = $this->activeGrant($context, $plan['id'] ?? null);
        if ($grant !== null) {
            return $this->allowed('complimentary_grant', 'COMPLIMENTARY', $plan, $configuration, [
                'grant_id' => (string) $grant->id,
                'expires_at' => $grant->ends_at,
            ]);
        }

        $subscription = $this->activeSubscription($context, $plan['id'] ?? null);
        if ($subscription !== null) {
            return $this->allowed('subscription', (string) $subscription->status, $plan, $configuration, [
                'subscription_id' => (string) $subscription->id,
                'provider' => (string) $subscription->provider,
                'current_period_end' => $subscription->current_period_end,
                'seat_quantity' => (int) $subscription->seat_quantity,
            ]);
        }

        return [
            'allowed' => false,
            'reason' => 'subscription_required',
            'status' => 'INACTIVE',
            'plan' => $plan,
            'configuration' => $configuration,
            'entitlements' => [],
            'subscription' => null,
            'message' => 'La cuenta no tiene un acceso activo a Rhino CRM.',
        ];
    }

    /** @return array<string,mixed> */
    public function configuration(): array
    {
        return [
            'enabled' => (bool) config('billing.enabled', false),
            'enforcement_enabled' => (bool) config('billing.enforcement_enabled', false),
            'providers' => [
                'stripe' => (bool) config('billing.providers.stripe.enabled', false),
                'google_play' => (bool) config('billing.providers.google_play.enabled', false),
                'apple' => (bool) config('billing.providers.apple.enabled', false),
            ],
            'checkout_available' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function plan(): array
    {
        $defaultCode = (string) config('billing.default_plan_code', 'rhino_crm_access');
        if (!Schema::hasTable('billing_plans')) {
            return [
                'id' => 'PLAN_RHINO_CRM_ACCESS',
                'code' => $defaultCode,
                'name' => 'Rhino CRM Access',
                'amount_minor' => 999,
                'currency' => 'USD',
                'interval_unit' => 'MONTH',
                'interval_count' => 1,
                'status' => 'DRAFT',
                'entitlements' => $this->defaultEntitlements(),
            ];
        }

        $row = DB::table('billing_plans')->where('code', $defaultCode)->first();
        if ($row === null) {
            return [
                'id' => null,
                'code' => $defaultCode,
                'name' => 'Rhino CRM Access',
                'amount_minor' => 999,
                'currency' => 'USD',
                'interval_unit' => 'MONTH',
                'interval_count' => 1,
                'status' => 'DRAFT',
                'entitlements' => $this->defaultEntitlements(),
            ];
        }

        return [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'description' => $row->description,
            'amount_minor' => (int) $row->amount_minor,
            'currency' => (string) $row->currency,
            'interval_unit' => (string) $row->interval_unit,
            'interval_count' => (int) $row->interval_count,
            'included_seats' => (int) $row->included_seats,
            'status' => (string) $row->status,
            'entitlements' => SqlValue::json($row->entitlements, $this->defaultEntitlements()),
        ];
    }

    /** @return array<string,mixed> */
    private function allowed(
        string $reason,
        string $status,
        array $plan,
        array $configuration,
        ?array $subscription,
    ): array {
        return [
            'allowed' => true,
            'reason' => $reason,
            'status' => $status,
            'plan' => $plan,
            'configuration' => $configuration,
            'entitlements' => $plan['entitlements'] ?? $this->defaultEntitlements(),
            'subscription' => $subscription,
            'message' => $reason === 'billing_disabled'
                ? 'Acceso habilitado. El cobro y la validación de suscripciones permanecen desactivados.'
                : 'Acceso activo.',
        ];
    }

    private function activeGrant(UserContextDTO $context, ?string $planId): ?object
    {
        if ($planId === null || !Schema::hasTable('billing_access_grants')) return null;

        return DB::table('billing_access_grants')
            ->where('plan_id', $planId)
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->where(function ($scope) use ($context): void {
                $scope->where('user_id', $context->userId);
                if ($context->partnerId !== null) {
                    $scope->orWhere(function ($partner) use ($context): void {
                        $partner->where('partner_id', $context->partnerId)->whereNull('user_id');
                    });
                }
            })
            ->where(function ($time): void {
                $time->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($time): void {
                $time->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->orderByDesc('created_at')
            ->first();
    }

    private function activeSubscription(UserContextDTO $context, ?string $planId): ?object
    {
        if ($context->partnerId === null || $planId === null) return null;
        if (!Schema::hasTable('billing_accounts') || !Schema::hasTable('billing_subscriptions')) return null;

        return DB::table('billing_subscriptions as subscription')
            ->join('billing_accounts as account', 'account.id', '=', 'subscription.billing_account_id')
            ->where('account.partner_id', $context->partnerId)
            ->where('account.status', 'ACTIVE')
            ->where('subscription.plan_id', $planId)
            ->whereIn('subscription.status', ['TRIALING', 'ACTIVE', 'GRACE_PERIOD', 'COMPLIMENTARY'])
            ->where(function ($period): void {
                $period->whereNull('subscription.current_period_end')
                    ->orWhere('subscription.current_period_end', '>', now());
            })
            ->select('subscription.*')
            ->orderByDesc('subscription.created_at')
            ->first();
    }

    /** @return string[] */
    private function defaultEntitlements(): array
    {
        return [
            'rhino.crm.access',
            'mobile.app.access',
            'contacts.access',
            'events.access',
            'nutrition.access',
            'followup.access',
            'sales.access',
        ];
    }
}
