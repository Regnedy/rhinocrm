<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Contracts\UserRepositoryInterface;
use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Entities\User;
use App\Modules\Partners\Services\PlanLimitService;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class UserAdminService
{
    public function __construct(
        private readonly HybridIdentityService $identities,
        private readonly UserRepositoryInterface $users,
        private readonly UserContextDTO $context,
        private readonly PlanLimitService $limits,
        private readonly RolePermissionService $rolePermissions,
    ) {}

    public function create(array $data): User
    {
        $partnerId = $this->context->isSuperAdmin() ? ($data['partner_id'] ?? null) : $this->context->partnerId;
        if (!is_string($partnerId) || $partnerId === '') {
            throw new \InvalidArgumentException('partner_id obligatorio.');
        }

        $result = $this->provision(
            $data,
            $partnerId,
            isset($data['location_id']) ? (string) $data['location_id'] : null,
            false,
        );

        if ((bool) config('firebase.auto_send_verification', true)) {
            $this->sendVerificationForUser($result['user']->getId());
        }

        return $result['user'];
    }

    /**
     * @return array{user:User,identity:array<string,mixed>}
     */
    public function createPrimaryPartnerAdmin(array $data, string $partnerId, string $locationId): array
    {
        if (DB::table('users')
            ->where('partner_id', $partnerId)
            ->where('role', 'PARTNER_ADMIN')
            ->whereNull('deleted_at')
            ->exists()) {
            throw new \RuntimeException('El socio ya tiene una cuenta principal.', 409);
        }

        $data['role'] = 'PARTNER_ADMIN';
        $data['partner_id'] = $partnerId;
        $data['location_id'] = $locationId;

        return $this->provision($data, $partnerId, $locationId, true);
    }

    /** @return array{firebase_enabled:bool,email_verified:bool,sent:bool,sent_at:?string,error:?string} */
    public function sendVerificationForUser(string $userId): array
    {
        $user = $this->userRow($userId);

        if (strtoupper((string) $user->auth_provider) === 'LOCAL') {
            DB::table('users')->where('id', $userId)->update([
                'firebase_email_verified_at' => $user->firebase_email_verified_at ?? now(),
                'firebase_verification_last_error' => null,
                'updated_at' => now(),
            ]);

            return [
                'firebase_enabled' => false,
                'email_verified' => true,
                'sent' => false,
                'sent_at' => null,
                'error' => null,
            ];
        }

        try {
            $result = $this->identities->sendEmailVerification((string) $user->email);
            DB::table('users')->where('id', $userId)->update([
                'firebase_email_verified_at' => $result['email_verified'] ? now() : null,
                'firebase_verification_sent_at' => $result['sent'] ? now() : $user->firebase_verification_sent_at,
                'firebase_verification_last_error' => null,
                'updated_at' => now(),
            ]);

            $this->audit($user, 'FIREBASE_VERIFICATION_SENT', [
                'sent' => $result['sent'],
                'already_verified' => $result['email_verified'],
            ]);

            return $result + ['error' => null];
        } catch (\Throwable $exception) {
            DB::table('users')->where('id', $userId)->update([
                'firebase_verification_last_error' => mb_substr($exception->getMessage(), 0, 1000),
                'updated_at' => now(),
            ]);

            $this->audit($user, 'FIREBASE_VERIFICATION_FAILED', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return [
                'firebase_enabled' => true,
                'email_verified' => false,
                'sent' => false,
                'sent_at' => null,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /** @return array{verification_link:string,email:string} */
    public function verificationLinkForUser(string $userId): array
    {
        $user = $this->userRow($userId);
        $link = $this->identities->getEmailVerificationLink((string) $user->email);

        $this->audit($user, 'FIREBASE_VERIFICATION_LINK_GENERATED');

        return ['verification_link' => $link, 'email' => (string) $user->email];
    }

    /** @return array{firebase_enabled:bool,exists:bool,email_verified:bool,disabled:bool,status:string} */
    public function refreshFirebaseStatusForUser(string $userId): array
    {
        $user = $this->userRow($userId);
        $status = $this->identities->status(
            $user->firebase_uid !== null ? (string) $user->firebase_uid : null,
            (string) $user->email,
        );

        $updates = [
            'firebase_email_verified_at' => $status['email_verified'] ? ($user->firebase_email_verified_at ?? now()) : null,
            'firebase_verification_last_error' => null,
            'updated_at' => now(),
        ];
        if ($status['exists'] && empty($user->firebase_uid) && $status['uid'] !== null) {
            $updates['firebase_uid'] = $status['uid'];
            $updates['auth_provider'] = $user->password_hash !== null ? 'HYBRID' : 'FIREBASE';
        }
        DB::table('users')->where('id', $userId)->update($updates);

        $label = !$status['firebase_enabled']
            ? 'NOT_APPLICABLE'
            : (!$status['exists'] ? 'MISSING' : ($status['email_verified'] ? 'VERIFIED' : 'PENDING'));

        return $status + ['status' => $label];
    }

    public function changePasswordForUser(string $userId, string $password): array
    {
        $user = $this->userRow($userId);
        $firebaseUid = trim((string) ($user->firebase_uid ?? ''));
        $identityCreated = false;

        if ($firebaseUid === '' && in_array((string) config('rhino.auth.driver', 'local'), ['firebase', 'hybrid'], true)) {
            $identity = $this->identities->create([
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'password' => $password,
            ], [
                'partner_id' => $user->partner_id,
                'role' => (string) $user->role,
                'location_id' => $user->location_id,
            ]);
            $firebaseUid = trim((string) ($identity['firebase_uid'] ?? ''));
            $identityCreated = $firebaseUid !== '';

            DB::table('users')->where('id', $userId)->update([
                'firebase_uid' => $identity['firebase_uid'],
                'auth_provider' => $identity['provider'],
                'firebase_email_verified_at' => $identity['email_verified'] ? now() : null,
                'updated_at' => now(),
            ]);
            $user = $this->userRow($userId);
        }

        if ($firebaseUid !== '') {
            $this->identities->changePassword($firebaseUid, $password, (string) $user->name);
        }

        $updates = ['updated_at' => now()];
        if (in_array(strtoupper((string) $user->auth_provider), ['LOCAL', 'HYBRID'], true)) {
            $updates['password_hash'] = Hash::make($password);
        }
        DB::table('users')->where('id', $userId)->update($updates);

        $verification = null;
        if ($firebaseUid !== '' && (bool) config('firebase.auto_send_verification', true)) {
            $verification = $this->sendVerificationForUser($userId);
        }

        $this->audit($user, $identityCreated ? 'FIREBASE_PRIMARY_ACCESS_REPAIRED' : 'PRIMARY_ACCESS_PASSWORD_CHANGED');

        return [
            'user_id' => $userId,
            'password_updated' => true,
            'firebase_repaired' => $identityCreated,
            'verification' => $verification,
            'sessions_must_reauthenticate' => true,
        ];
    }

    public function discardIdentity(array $identity): void
    {
        $this->identities->delete($identity);
    }

    /**
     * @return array{user:User,identity:array<string,mixed>}
     */
    private function provision(array $data, string $partnerId, ?string $locationId, bool $primary): array
    {
        $this->limits->assertCanAddUser($partnerId);

        $locationId = $this->resolveLocation($partnerId, $locationId);
        $email = mb_strtolower(trim((string) $data['email']));
        if (DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw new \RuntimeException('El correo ya está registrado en Rhino CRM.', 409);
        }

        $role = (string) $data['role'];
        $permissions = $this->rolePermissions->resolve($role, (array) ($data['permissions'] ?? []), $partnerId);
        $claims = [
            'partner_id' => $partnerId,
            'role' => $role,
            'location_id' => $locationId,
        ];
        $identity = $this->identities->create([
            'name' => trim((string) $data['name']),
            'email' => $email,
            'password' => (string) $data['password'],
        ], $claims);

        try {
            $user = DB::transaction(function () use ($data, $partnerId, $locationId, $permissions, $identity, $email, $role, $primary): User {
                $makePrimary = $primary || ($role === 'PARTNER_ADMIN' && !DB::table('users')
                    ->where('partner_id', $partnerId)
                    ->where('is_primary_partner_admin', true)
                    ->whereNull('deleted_at')
                    ->exists());

                $user = new User(
                    id: (string) $identity['id'],
                    partnerId: $partnerId,
                    role: $role,
                    name: trim((string) $data['name']),
                    email: $email,
                    status: 'ACTIVE',
                    locationId: $locationId,
                );
                $this->users->save($user);
                DB::table('users')->where('id', $user->getId())->update([
                    'password_hash' => $identity['password_hash'],
                    'auth_provider' => $identity['provider'],
                    'firebase_uid' => $identity['firebase_uid'],
                    'permissions' => SqlValue::encode($permissions),
                    'is_primary_partner_admin' => $makePrimary,
                    'firebase_email_verified_at' => $identity['email_verified'] ? now() : null,
                    'firebase_verification_sent_at' => null,
                    'firebase_verification_last_error' => null,
                    'updated_at' => now(),
                ]);

                DB::table('security_audit_logs')->insert([
                    'actor_user_id' => $this->context->userId,
                    'partner_id' => $partnerId,
                    'target_type' => 'USER',
                    'target_id' => $user->getId(),
                    'action' => $makePrimary ? 'PRIMARY_PARTNER_ACCESS_CREATED' : 'USER_CREATED',
                    'reason' => $makePrimary ? 'Cuenta principal creada junto con el socio.' : 'Usuario creado desde Rhino CRM.',
                    'ip_address' => null,
                    'metadata' => SqlValue::encode([
                        'email' => $email,
                        'role' => $role,
                        'provider' => $identity['provider'],
                        'firebase_uid_suffix' => $identity['firebase_uid'] ? substr((string) $identity['firebase_uid'], -8) : null,
                    ]),
                    'created_at' => now(),
                ]);

                return $user;
            }, 3);
        } catch (\Throwable $exception) {
            $this->identities->delete($identity);
            throw $exception;
        }

        return ['user' => $user, 'identity' => $identity];
    }

    private function resolveLocation(string $partnerId, ?string $locationId): string
    {
        $locationId = $locationId !== null && trim($locationId) !== '' ? trim($locationId) : null;
        if ($locationId === null) {
            $locationId = DB::table('locations')
                ->where('partner_id', $partnerId)
                ->where('status', 'ACTIVE')
                ->whereNull('deleted_at')
                ->orderBy('created_at')
                ->value('id');
            $locationId = $locationId !== null ? (string) $locationId : null;
        }
        if ($locationId === null) {
            throw new \InvalidArgumentException('No existe una ubicación técnica activa para el socio.');
        }

        $location = DB::table('locations')
            ->where('id', $locationId)
            ->where('partner_id', $partnerId)
            ->where('status', 'ACTIVE')
            ->whereNull('deleted_at')
            ->first();
        if ($location === null) {
            throw new \InvalidArgumentException('La ubicación no pertenece al socio o no está activa.');
        }

        return $locationId;
    }

    private function userRow(string $userId): object
    {
        $user = DB::table('users')->where('id', $userId)->whereNull('deleted_at')->first();
        if ($user === null) {
            throw new \RuntimeException('Cuenta principal no encontrada.', 404);
        }

        if (!$this->context->isSuperAdmin() && (string) $user->partner_id !== (string) $this->context->partnerId) {
            throw new \RuntimeException('No tienes acceso a esta cuenta.', 403);
        }

        return $user;
    }

    private function audit(object $user, string $action, array $metadata = []): void
    {
        DB::table('security_audit_logs')->insert([
            'actor_user_id' => $this->context->userId,
            'partner_id' => $user->partner_id,
            'target_type' => 'USER',
            'target_id' => $user->id,
            'action' => $action,
            'reason' => null,
            'ip_address' => null,
            'metadata' => SqlValue::encode($metadata),
            'created_at' => now(),
        ]);
    }
}
