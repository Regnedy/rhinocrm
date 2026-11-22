<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\AuthenticationFailureException;
use App\Support\Database\SqlValue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ApiSessionService
{
    public function __construct(private readonly RolePermissionService $rolePermissions) {}

    /** @return array{access_token:string,session_id:string,expires_at:string} */
    public function create(string $userId, array $device, Request $request): array
    {
        $user = DB::table('users')->where('id', $userId)->first();
        if ($user === null) {
            throw new AuthenticationFailureException('USER_NOT_FOUND', 'Usuario no encontrado.');
        }
        $this->assertAccessState($user);

        $sessionId = 'SES_'.strtoupper((string) Str::ulid());
        $secret = bin2hex(random_bytes(32));
        $token = 'rhino_'.$sessionId.'.'.$secret;
        $expiresAt = now()->addDays(max(1, (int) config('rhino.auth.session_days', 30)));
        $fingerprint = trim((string) ($device['device_fingerprint'] ?? $device['android_id'] ?? ''));

        DB::table('api_sessions')->insert([
            'id' => $sessionId,
            'user_id' => $userId,
            'partner_id' => $user->partner_id,
            'token_hash' => hash('sha256', $token),
            'device_name' => $device['device_name'] ?? null,
            'device_fingerprint_hash' => $fingerprint !== '' ? hash('sha256', $fingerprint) : null,
            'platform' => strtoupper((string) ($device['platform'] ?? 'ANDROID')),
            'app_version' => $device['app_version'] ?? null,
            'fcm_token' => $device['fcm_token'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'last_seen_at' => now(),
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('users')->where('id', $userId)->update(['last_login_at' => now(), 'updated_at' => now()]);

        return ['access_token' => $token, 'session_id' => $sessionId, 'expires_at' => $expiresAt->toAtomString()];
    }

    public function contextFromToken(string $token): UserContextDTO
    {
        $session = DB::table('api_sessions')->where('token_hash', hash('sha256', $token))->first();
        if ($session === null || $session->revoked_at !== null) {
            throw new AuthenticationFailureException('SESSION_INVALID', 'Sesión inválida o revocada.');
        }
        if (now()->greaterThanOrEqualTo($session->expires_at)) {
            throw new AuthenticationFailureException('SESSION_EXPIRED', 'La sesión expiró.');
        }

        $user = DB::table('users')->where('id', $session->user_id)->first();
        if ($user === null) {
            throw new AuthenticationFailureException('USER_NOT_FOUND', 'Usuario no encontrado.');
        }
        $this->assertAccessState($user);

        $touchSeconds = max(60, (int) config('rhino.auth.touch_interval_seconds', 300));
        if ($session->last_seen_at === null || now()->diffInSeconds($session->last_seen_at) >= $touchSeconds) {
            DB::table('api_sessions')->where('id', $session->id)->update(['last_seen_at' => now(), 'updated_at' => now()]);
        }

        $permissions = $this->rolePermissions->resolve((string) $user->role, SqlValue::json($user->permissions), $user->partner_id !== null ? (string) $user->partner_id : null);
        return new UserContextDTO(
            (string) $user->id,
            $user->partner_id !== null ? (string) $user->partner_id : null,
            (string) $user->role,
            $user->location_id !== null ? (string) $user->location_id : null,
            $permissions,
        );
    }

    public function revokeToken(string $token, string $actorId, string $reason = 'Cierre de sesión'): void
    {
        DB::table('api_sessions')->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->update([
            'revoked_at' => now(), 'revoked_by' => $actorId, 'revocation_reason' => $reason, 'updated_at' => now(),
        ]);
    }

    public function revokeForUser(string $userId, string $actorId, string $reason): int
    {
        return DB::table('api_sessions')->where('user_id', $userId)->whereNull('revoked_at')->update([
            'revoked_at' => now(), 'revoked_by' => $actorId, 'revocation_reason' => $reason, 'updated_at' => now(),
        ]);
    }

    public function revokeForPartner(string $partnerId, string $actorId, string $reason): int
    {
        return DB::table('api_sessions')->where('partner_id', $partnerId)->whereNull('revoked_at')->update([
            'revoked_at' => now(), 'revoked_by' => $actorId, 'revocation_reason' => $reason, 'updated_at' => now(),
        ]);
    }

    private function assertAccessState(object $user): void
    {
        if ((string) $user->status !== 'ACTIVE' || $user->deleted_at !== null) {
            throw new AuthenticationFailureException('USER_SUSPENDED', 'El usuario está suspendido.', 403);
        }
        if ($user->partner_id !== null) {
            $partner = DB::table('partners')->where('id', $user->partner_id)->first();
            if ($partner === null || (string) $partner->status !== 'ACTIVE' || $partner->deleted_at !== null) {
                throw new AuthenticationFailureException('PARTNER_SUSPENDED', 'El socio está suspendido.', 403);
            }
        }
        if ($user->location_id !== null) {
            $location = DB::table('locations')->where('id', $user->location_id)->first();
            if ($location === null || (string) $location->status !== 'ACTIVE' || $location->deleted_at !== null) {
                throw new AuthenticationFailureException('LOCATION_SUSPENDED', 'La ubicación está suspendida.', 403);
            }
        }
    }
}
