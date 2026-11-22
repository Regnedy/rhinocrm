<?php

namespace App\Modules\Billing\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class BillingAdminService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly BillingAccessService $access,
    ) {}

    /** @return array<string,mixed> */
    public function overview(): array
    {
        $this->requireSuperAdmin();

        return [
            'configuration' => $this->access->configuration(),
            'plan' => $this->access->plan(),
            'counts' => [
                'accounts' => $this->count('billing_accounts'),
                'subscriptions' => $this->count('billing_subscriptions'),
                'active_subscriptions' => $this->count('billing_subscriptions', ['status' => ['TRIALING', 'ACTIVE', 'GRACE_PERIOD', 'COMPLIMENTARY']]),
                'access_grants' => $this->count('billing_access_grants', ['status' => ['ACTIVE']]),
                'registered_mobile_devices' => $this->count('mobile_app_devices', ['status' => ['ACTIVE']]),
            ],
            'activation_guard' => [
                'ready' => false,
                'reason' => 'Los proveedores, webhooks y la exigencia de suscripción están desactivados.',
                'required_flags' => [
                    'BILLING_ENABLED=true',
                    'SUBSCRIPTION_ENFORCEMENT_ENABLED=true',
                ],
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function plans(): array
    {
        $this->requireSuperAdmin();
        if (!Schema::hasTable('billing_plans')) return [];

        return DB::table('billing_plans')->orderBy('name')->get()->map(static fn (object $row): array => [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'amount_minor' => (int) $row->amount_minor,
            'currency' => (string) $row->currency,
            'interval_unit' => (string) $row->interval_unit,
            'interval_count' => (int) $row->interval_count,
            'included_seats' => (int) $row->included_seats,
            'status' => (string) $row->status,
            'entitlements' => SqlValue::json($row->entitlements),
        ])->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function subscriptions(int $limit = 100): array
    {
        $this->requireSuperAdmin();
        if (!Schema::hasTable('billing_subscriptions')) return [];

        return DB::table('billing_subscriptions as subscription')
            ->leftJoin('billing_accounts as account', 'account.id', '=', 'subscription.billing_account_id')
            ->leftJoin('partners as partner', 'partner.id', '=', 'account.partner_id')
            ->leftJoin('billing_plans as plan', 'plan.id', '=', 'subscription.plan_id')
            ->select(
                'subscription.*', 'account.partner_id', 'partner.business_name as partner_name',
                'plan.code as plan_code', 'plan.name as plan_name',
            )
            ->orderByDesc('subscription.created_at')
            ->limit(min(max($limit, 1), 500))
            ->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    /** @return array<string,mixed> */
    public function grant(array $data): array
    {
        $this->requireSuperAdmin();
        if (!Schema::hasTable('billing_access_grants')) {
            throw new \RuntimeException('La migración de facturación todavía no está instalada.', 409);
        }

        $partnerId = isset($data['partner_id']) && $data['partner_id'] !== '' ? (string) $data['partner_id'] : null;
        $userId = isset($data['user_id']) && $data['user_id'] !== '' ? (string) $data['user_id'] : null;
        if ($partnerId === null && $userId === null) {
            throw new \RuntimeException('Debes indicar un socio o un usuario.', 422);
        }

        $planId = (string) ($data['plan_id'] ?? ($this->access->plan()['id'] ?? ''));
        if ($planId === '') throw new \RuntimeException('Plan no disponible.', 422);

        $id = 'GRANT_'.strtoupper((string) Str::ulid());
        DB::table('billing_access_grants')->insert([
            'id' => $id,
            'partner_id' => $partnerId,
            'user_id' => $userId,
            'plan_id' => $planId,
            'status' => 'ACTIVE',
            'reason' => isset($data['reason']) ? trim((string) $data['reason']) : 'Acceso administrativo',
            'starts_at' => $data['starts_at'] ?? now(),
            'ends_at' => $data['ends_at'] ?? null,
            'granted_by_user_id' => $this->context->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (array) DB::table('billing_access_grants')->where('id', $id)->first();
    }

    /** @return array<string,mixed> */
    public function revoke(string $id): array
    {
        $this->requireSuperAdmin();
        $row = Schema::hasTable('billing_access_grants')
            ? DB::table('billing_access_grants')->where('id', $id)->first()
            : null;
        if ($row === null) throw new \RuntimeException('Acceso manual no encontrado.', 404);

        DB::table('billing_access_grants')->where('id', $id)->update([
            'status' => 'REVOKED',
            'revoked_at' => now(),
            'revoked_by_user_id' => $this->context->userId,
            'updated_at' => now(),
        ]);

        return (array) DB::table('billing_access_grants')->where('id', $id)->first();
    }

    /** @param array{status?:string[]} $filters */
    private function count(string $table, array $filters = []): int
    {
        if (!Schema::hasTable($table)) return 0;
        $query = DB::table($table);
        if (isset($filters['status'])) $query->whereIn('status', $filters['status']);
        return $query->count();
    }

    private function requireSuperAdmin(): void
    {
        if (!$this->context->isSuperAdmin()) {
            throw new \RuntimeException('Solo el administrador global puede administrar la facturación.', 403);
        }
    }
}
