<?php

namespace App\Modules\Partners\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Services\ApiSessionService;
use App\Modules\Auth\Services\HybridIdentityService;
use App\Modules\Auth\Services\RolePermissionService;
use App\Modules\Auth\Services\UserAdminService;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;

final class PartnerPrimaryAccessService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly UserAdminService $users,
        private readonly ApiSessionService $sessions,
        private readonly HybridIdentityService $identities,
        private readonly RolePermissionService $rolePermissions,
    ) {}

    public function create(string $partnerId, array $data): array
    {
        $partner = $this->partner($partnerId);
        if ($this->primaryUser($partnerId, false) !== null) {
            throw new \RuntimeException('El socio ya tiene una cuenta principal.', 409);
        }

        $locationId = $this->primaryLocationId($partnerId);

        $result = $this->users->createPrimaryPartnerAdmin([
            'name' => trim((string) $data['name']),
            'email' => mb_strtolower(trim((string) $data['email'])),
            'password' => (string) $data['password'],
            'role' => 'PARTNER_ADMIN',
        ], $partnerId, $locationId);

        $verification = $this->users->sendVerificationForUser($result['user']->getId());

        return [
            'partner_id' => $partnerId,
            'partner_name' => (string) $partner->business_name,
            'account' => $this->summary($this->primaryUser($partnerId)),
            'verification' => $verification,
        ];
    }

    /**
     * Preflight para resolver correos ya existentes sin recurrir a SSH.
     *
     * @return array<string,mixed>
     */
    public function emailStatus(string $partnerId, string $email): array
    {
        $target = $this->partner($partnerId);
        if ($this->primaryUser($partnerId, false) !== null) {
            throw new \RuntimeException('El socio ya tiene una cuenta principal.', 409);
        }

        $email = mb_strtolower(trim($email));
        $existing = DB::table('users as u')
            ->leftJoin('partners as p', 'p.id', '=', 'u.partner_id')
            ->whereRaw('LOWER(u.email) = ?', [$email])
            ->whereNull('u.deleted_at')
            ->select([
                'u.id',
                'u.partner_id',
                'u.location_id',
                'u.role',
                'u.name',
                'u.email',
                'u.status',
                'u.is_primary_partner_admin',
                'u.firebase_uid',
                'u.firebase_email_verified_at',
                'u.last_login_at',
                'p.business_name as partner_name',
                'p.status as partner_status',
            ])
            ->first();

        if ($existing === null) {
            return [
                'available' => true,
                'can_transfer' => false,
                'target_partner' => [
                    'id' => $partnerId,
                    'name' => (string) $target->business_name,
                ],
                'existing' => null,
            ];
        }

        $samePartner = (string) ($existing->partner_id ?? '') === $partnerId;
        $activeSessions = DB::table('api_sessions')
            ->where('user_id', (string) $existing->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->count();

        $otherActiveAdmins = 0;
        if ($existing->partner_id !== null) {
            $otherActiveAdmins = DB::table('users')
                ->where('partner_id', (string) $existing->partner_id)
                ->where('id', '!=', (string) $existing->id)
                ->where('role', 'PARTNER_ADMIN')
                ->where('status', 'ACTIVE')
                ->whereNull('deleted_at')
                ->count();
        }

        $blockReason = null;
        if ((string) $existing->role === 'SUPER_ADMIN') {
            $blockReason = 'Una cuenta SUPER_ADMIN no puede transferirse a un socio.';
        } elseif (!$samePartner && (bool) $existing->is_primary_partner_admin && $otherActiveAdmins === 0) {
            $blockReason = 'La cuenta es el único administrador principal de su socio actual.';
        } elseif (!$samePartner && $existing->last_login_at !== null) {
            $blockReason = 'La cuenta ya inició sesión. Para proteger su historial, utiliza otro correo.';
        } elseif (!$samePartner && $activeSessions > 0) {
            $blockReason = 'La cuenta mantiene sesiones activas. Ciérralas desde su socio actual antes de transferirla.';
        }

        $canTransfer = $blockReason === null;

        return [
            'available' => false,
            'can_transfer' => $canTransfer,
            'same_partner' => $samePartner,
            'mode' => $samePartner ? 'PROMOTE' : 'TRANSFER',
            'block_reason' => $blockReason,
            'target_partner' => [
                'id' => $partnerId,
                'name' => (string) $target->business_name,
            ],
            'existing' => [
                'id' => (string) $existing->id,
                'name' => (string) $existing->name,
                'email' => (string) $existing->email,
                'role' => (string) $existing->role,
                'status' => (string) $existing->status,
                'partner_id' => $existing->partner_id !== null ? (string) $existing->partner_id : null,
                'partner_name' => $existing->partner_name !== null ? (string) $existing->partner_name : null,
                'partner_status' => $existing->partner_status !== null ? (string) $existing->partner_status : null,
                'is_primary' => (bool) $existing->is_primary_partner_admin,
                'last_login_at' => $existing->last_login_at,
                'active_sessions' => $activeSessions,
                'firebase_uid_present' => !empty($existing->firebase_uid),
                'email_verified' => $existing->firebase_email_verified_at !== null,
            ],
        ];
    }

    /**
     * Transfiere o promueve una cuenta existente como cuenta principal del socio.
     * Solo se habilita para cuentas sin uso previo y con confirmación explícita.
     *
     * @return array<string,mixed>
     */
    public function transferExisting(string $partnerId, array $data): array
    {
        $target = $this->partner($partnerId);
        if ($this->primaryUser($partnerId, false) !== null) {
            throw new \RuntimeException('El socio ya tiene una cuenta principal.', 409);
        }

        if (strtoupper(trim((string) ($data['confirmation'] ?? ''))) !== 'TRANSFERIR') {
            throw new \RuntimeException('Escribe TRANSFERIR para confirmar el cambio de socio.', 422);
        }

        $email = mb_strtolower(trim((string) $data['email']));
        $status = $this->emailStatus($partnerId, $email);
        if (($status['available'] ?? false) === true) {
            throw new \RuntimeException('El correo ya no está registrado. Crea la cuenta normalmente.', 409);
        }
        if (($status['can_transfer'] ?? false) !== true) {
            throw new \RuntimeException((string) ($status['block_reason'] ?? 'La cuenta no puede transferirse.'), 409);
        }

        $existingId = (string) ($status['existing']['id'] ?? '');
        $existing = DB::table('users')->where('id', $existingId)->whereNull('deleted_at')->first();
        if ($existing === null) {
            throw new \RuntimeException('La cuenta existente ya no está disponible.', 404);
        }

        $locationId = $this->primaryLocationId($partnerId);
        $sourcePartnerId = $existing->partner_id !== null ? (string) $existing->partner_id : null;
        $sourceLocationId = $existing->location_id !== null ? (string) $existing->location_id : null;
        $sourceRole = (string) $existing->role;
        $identity = null;
        $permissions = $this->rolePermissions->resolve('PARTNER_ADMIN', [], $partnerId);

        try {
            DB::transaction(function () use (
                $existing,
                $existingId,
                $partnerId,
                $locationId,
                $data,
                $email,
                $permissions,
                $sourcePartnerId,
                &$identity,
            ): void {
                // Sincroniza nombre, contraseña y claims compactos en Firebase.
                $identity = $this->identities->create([
                    'name' => trim((string) $data['name']),
                    'email' => $email,
                    'password' => (string) $data['password'],
                ], [
                    'partner_id' => $partnerId,
                    'role' => 'PARTNER_ADMIN',
                    'location_id' => $locationId,
                ]);

                if ($sourcePartnerId !== null && (bool) $existing->is_primary_partner_admin) {
                    $replacementId = DB::table('users')
                        ->where('partner_id', $sourcePartnerId)
                        ->where('id', '!=', $existingId)
                        ->where('role', 'PARTNER_ADMIN')
                        ->where('status', 'ACTIVE')
                        ->whereNull('deleted_at')
                        ->orderBy('created_at')
                        ->value('id');

                    if ($replacementId !== null) {
                        DB::table('users')->where('id', $replacementId)->update([
                            'is_primary_partner_admin' => true,
                            'updated_at' => now(),
                        ]);
                    }
                }

                DB::table('users')->where('id', $existingId)->update([
                    'partner_id' => $partnerId,
                    'location_id' => $locationId,
                    'role' => 'PARTNER_ADMIN',
                    'name' => trim((string) $data['name']),
                    'email' => $email,
                    'password_hash' => $identity['password_hash'],
                    'auth_provider' => $identity['provider'],
                    'firebase_uid' => $identity['firebase_uid'],
                    'permissions' => SqlValue::encode($permissions),
                    'status' => 'ACTIVE',
                    'is_primary_partner_admin' => true,
                    'firebase_email_verified_at' => $identity['email_verified'] ? now() : null,
                    'firebase_verification_last_error' => null,
                    'last_login_at' => null,
                    'suspended_at' => null,
                    'suspended_by' => null,
                    'suspension_reason' => null,
                    'updated_at' => now(),
                ]);

                DB::table('security_audit_logs')->insert([
                    'actor_user_id' => $this->context->userId,
                    'partner_id' => $partnerId,
                    'target_type' => 'USER',
                    'target_id' => $existingId,
                    'action' => $sourcePartnerId === $partnerId
                        ? 'PRIMARY_PARTNER_ACCESS_PROMOTED'
                        : 'PRIMARY_PARTNER_ACCESS_TRANSFERRED',
                    'reason' => trim((string) ($data['reason'] ?? 'Corrección administrativa desde Socios.')),
                    'ip_address' => null,
                    'metadata' => SqlValue::encode([
                        'email' => $email,
                        'source_partner_id' => $sourcePartnerId,
                        'target_partner_id' => $partnerId,
                        'firebase_uid_suffix' => $identity['firebase_uid']
                            ? substr((string) $identity['firebase_uid'], -8)
                            : null,
                    ]),
                    'created_at' => now(),
                ]);
            }, 3);
        } catch (\Throwable $exception) {
            if (is_array($identity)) {
                $this->users->discardIdentity($identity);
            }
            throw $exception;
        }

        $revoked = $this->sessions->revokeForUser(
            $existingId,
            $this->context->userId,
            'Cuenta transferida a otro socio por SUPER_ADMIN.',
        );
        $verification = $this->users->sendVerificationForUser($existingId);

        return [
            'partner_id' => $partnerId,
            'partner_name' => (string) $target->business_name,
            'source_partner_id' => $sourcePartnerId,
            'source_location_id' => $sourceLocationId,
            'source_role' => $sourceRole,
            'revoked_sessions' => $revoked,
            'account' => $this->summary($this->primaryUser($partnerId)),
            'verification' => $verification,
        ];
    }

    public function resendVerification(string $partnerId): array
    {
        $user = $this->primaryUser($partnerId);
        $result = $this->users->sendVerificationForUser((string) $user->id);

        return [
            'partner_id' => $partnerId,
            'account' => $this->summary($this->primaryUser($partnerId)),
            'verification' => $result,
        ];
    }

    public function verificationLink(string $partnerId): array
    {
        $user = $this->primaryUser($partnerId);

        return $this->users->verificationLinkForUser((string) $user->id) + [
            'partner_id' => $partnerId,
            'user_id' => (string) $user->id,
        ];
    }

    public function refresh(string $partnerId): array
    {
        $user = $this->primaryUser($partnerId);
        $firebase = $this->users->refreshFirebaseStatusForUser((string) $user->id);

        return [
            'partner_id' => $partnerId,
            'account' => $this->summary($this->primaryUser($partnerId)),
            'firebase' => $firebase,
        ];
    }

    public function changePassword(string $partnerId, string $password): array
    {
        $user = $this->primaryUser($partnerId);
        $result = $this->users->changePasswordForUser((string) $user->id, $password);
        $revoked = $this->sessions->revokeForUser(
            (string) $user->id,
            $this->context->userId,
            'Contraseña de la cuenta principal actualizada por SUPER_ADMIN.',
        );

        return $result + [
            'partner_id' => $partnerId,
            'revoked_sessions' => $revoked,
        ];
    }

    private function partner(string $partnerId): object
    {
        if (!$this->context->isSuperAdmin()) {
            throw new \RuntimeException('Solo SUPER_ADMIN puede administrar el acceso principal.', 403);
        }

        $partner = DB::table('partners')->where('id', $partnerId)->whereNull('deleted_at')->first();
        if ($partner === null) {
            throw new \RuntimeException('Socio no encontrado.', 404);
        }

        return $partner;
    }

    private function primaryLocationId(string $partnerId): string
    {
        $locationId = DB::table('locations')
            ->where('partner_id', $partnerId)
            ->where('status', 'ACTIVE')
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->value('id');

        if ($locationId === null) {
            throw new \RuntimeException('El socio no tiene una ubicación activa.', 422);
        }

        return (string) $locationId;
    }

    private function primaryUser(string $partnerId, bool $required = true): ?object
    {
        $this->partner($partnerId);
        $user = DB::table('users')
            ->where('partner_id', $partnerId)
            ->where('role', 'PARTNER_ADMIN')
            ->whereNull('deleted_at')
            ->orderByDesc('is_primary_partner_admin')
            ->orderByRaw("CASE WHEN status = 'ACTIVE' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->first();

        if ($required && $user === null) {
            throw new \RuntimeException('El socio todavía no tiene una cuenta principal.', 404);
        }

        return $user;
    }

    private function summary(?object $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $provider = strtoupper((string) $user->auth_provider);
        $verificationStatus = $provider === 'LOCAL'
            ? 'NOT_APPLICABLE'
            : ($user->firebase_email_verified_at !== null
                ? 'VERIFIED'
                : (!empty($user->firebase_uid) ? 'PENDING' : 'MISSING'));

        return [
            'id' => (string) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'status' => (string) $user->status,
            'auth_provider' => $provider,
            'firebase_uid_present' => !empty($user->firebase_uid),
            'verification_status' => $verificationStatus,
            'firebase_email_verified_at' => $user->firebase_email_verified_at,
            'firebase_verification_sent_at' => $user->firebase_verification_sent_at,
            'firebase_verification_last_error' => $user->firebase_verification_last_error,
            'last_login_at' => $user->last_login_at,
        ];
    }
}
