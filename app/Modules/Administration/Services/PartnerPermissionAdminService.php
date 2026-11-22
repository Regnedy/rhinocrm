<?php

namespace App\Modules\Administration\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Services\PartnerPermissionPolicyService;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;

final class PartnerPermissionAdminService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly PartnerPermissionPolicyService $policies,
        private readonly SecurityAuditService $audit,
    ) {}

    public function catalog(): array
    {
        $this->requireSuperAdmin();

        return [
            'groups' => $this->policies->catalog(),
            'permission_count' => count($this->policies->codes()),
        ];
    }

    public function show(string $partnerId): array
    {
        $this->requireSuperAdmin();
        $partner = $this->partner($partnerId);
        $policy = $this->policies->policy($partnerId);

        return [
            'partner' => [
                'id' => (string) $partner->id,
                'partner_number' => $partner->partner_number !== null ? (string) $partner->partner_number : null,
                'business_name' => (string) $partner->business_name,
                'status' => (string) $partner->status,
            ],
            'policy' => $policy,
            'permission_count' => count($policy['permissions']),
            'total_permission_count' => count($this->policies->codes()),
            'effect' => 'Los permisos del socio funcionan como límite máximo. Los roles y permisos individuales todavía pueden restringir más el acceso.',
        ];
    }

    /** @param string[] $permissions */
    public function update(string $partnerId, array $permissions, ?string $reason = null): array
    {
        $this->requireSuperAdmin();
        $this->partner($partnerId);

        $before = $this->policies->policy($partnerId);
        $normalized = $this->policies->normalize($permissions);
        $now = now();

        DB::transaction(function () use ($partnerId, $normalized, $reason, $before, $now): void {
            $values = [
                'permissions' => SqlValue::encode($normalized),
                'updated_by' => $this->context->userId,
                'change_reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
                'updated_at' => $now,
            ];

            if ($before['custom']) {
                DB::table('partner_permission_policies')->where('partner_id', $partnerId)->update($values);
            } else {
                DB::table('partner_permission_policies')->insert($values + [
                    'partner_id' => $partnerId,
                    'created_at' => $now,
                ]);
            }

            $this->audit->record(
                'PARTNER_PERMISSION_POLICY',
                $partnerId,
                'UPDATE',
                $partnerId,
                $reason,
                [
                    'previous_mode' => $before['custom'] ? 'CUSTOM' : 'DEFAULT',
                    'previous_permissions' => $before['permissions'],
                    'permissions' => $normalized,
                    'permission_count' => count($normalized),
                ],
            );
        }, 3);

        return $this->show($partnerId) + [
            'message' => 'Permisos del socio actualizados.',
            'refresh_required' => true,
        ];
    }

    public function reset(string $partnerId, ?string $reason = null): array
    {
        $this->requireSuperAdmin();
        $this->partner($partnerId);

        $before = $this->policies->policy($partnerId);
        DB::transaction(function () use ($partnerId, $reason, $before): void {
            DB::table('partner_permission_policies')->where('partner_id', $partnerId)->delete();

            $this->audit->record(
                'PARTNER_PERMISSION_POLICY',
                $partnerId,
                'RESET',
                $partnerId,
                $reason,
                [
                    'previous_mode' => $before['custom'] ? 'CUSTOM' : 'DEFAULT',
                    'previous_permissions' => $before['permissions'],
                ],
            );
        }, 3);

        return $this->show($partnerId) + [
            'message' => 'Se restauró el acceso predeterminado del socio.',
            'refresh_required' => true,
        ];
    }

    private function requireSuperAdmin(): void
    {
        if (!$this->context->isSuperAdmin()) {
            throw new \RuntimeException('Solo SUPER_ADMIN puede administrar permisos de socios.', 403);
        }
    }

    private function partner(string $partnerId): object
    {
        return DB::table('partners')
            ->where('id', $partnerId)
            ->whereNull('deleted_at')
            ->first()
            ?? throw new \RuntimeException('Socio no encontrado.', 404);
    }
}
