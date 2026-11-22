<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\AuthenticationFailureException;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;

final class AccessTokenService
{
    public function __construct(
        private readonly ApiSessionService $sessions,
        private readonly FirebaseAuthService $firebase,
        private readonly RolePermissionService $rolePermissions,
    ) {}

    public function verify(string $token): UserContextDTO
    {
        if (str_starts_with($token, 'rhino_')) {
            return $this->sessions->contextFromToken($token);
        }

        $driver = (string) config('rhino.auth.driver', 'local');
        if (!in_array($driver, ['firebase', 'hybrid'], true)) {
            throw new AuthenticationFailureException('AUTH_TOKEN_INVALID', 'El token no corresponde a una sesión Rhino.');
        }

        if (!(bool) config('firebase.allow_direct_api_tokens', false)) {
            throw new AuthenticationFailureException(
                'SESSION_EXCHANGE_REQUIRED',
                'Intercambia primero el token Firebase por una sesión Rhino.',
                401,
            );
        }

        $identity = $this->firebase->verifyIdentityToken($token);
        $user = DB::table('users')
            ->where('firebase_uid', $identity['uid'])
            ->first();
        if ($user === null) {
            throw new AuthenticationFailureException('USER_NOT_PROVISIONED', 'El usuario Firebase aún no está vinculado al CRM.', 403);
        }

        // Reutiliza las mismas comprobaciones de estado mediante una sesión efímera en memoria.
        if ((string) $user->status !== 'ACTIVE' || $user->deleted_at !== null) {
            throw new AuthenticationFailureException('USER_SUSPENDED', 'El usuario está suspendido.', 403);
        }
        if ($user->partner_id !== null) {
            $partner = DB::table('partners')->where('id', $user->partner_id)->first();
            if ($partner === null || (string) $partner->status !== 'ACTIVE' || $partner->deleted_at !== null) {
                throw new AuthenticationFailureException('PARTNER_SUSPENDED', 'El socio está suspendido.', 403);
            }
        }

        return new UserContextDTO(
            (string) $user->id,
            $user->partner_id !== null ? (string) $user->partner_id : null,
            (string) $user->role,
            $user->location_id !== null ? (string) $user->location_id : null,
            $this->rolePermissions->resolve((string) $user->role, SqlValue::json($user->permissions), $user->partner_id !== null ? (string) $user->partner_id : null),
        );
    }
}
