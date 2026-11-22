<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Domain\Exceptions\AuthenticationFailureException;
use App\Support\Database\SqlValue;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Auth;

final class FirebaseIdentityLinkService
{
    public function __construct(
        private readonly Container $container,
        private readonly RolePermissionService $rolePermissions,
    ) {}

    /** @param array{uid:string,email:?string,email_verified:bool,claims:array<string,mixed>} $identity */
    public function resolveOrLink(array $identity, Request $request): object
    {
        $uid = trim($identity['uid']);
        $linked = DB::table('users')->where('firebase_uid', $uid)->first();
        if ($linked !== null) {
            if ((bool) ($identity['email_verified'] ?? false) && $linked->firebase_email_verified_at === null) {
                DB::table('users')->where('id', $linked->id)->update([
                    'firebase_email_verified_at' => now(),
                    'firebase_verification_last_error' => null,
                    'updated_at' => now(),
                ]);
                $linked = DB::table('users')->where('id', $linked->id)->first();
            }
            $this->syncClaimsBestEffort($linked);
            return $linked;
        }

        if (!(bool) config('firebase.auto_link_by_email', true)) {
            throw new AuthenticationFailureException(
                'USER_NOT_PROVISIONED',
                'La cuenta Firebase todavía no está vinculada al CRM.',
                403,
            );
        }

        $email = isset($identity['email']) ? mb_strtolower(trim((string) $identity['email'])) : '';
        if ($email === '' || !(bool) ($identity['email_verified'] ?? false)) {
            throw new AuthenticationFailureException(
                'FIREBASE_EMAIL_NOT_VERIFIED',
                'Se requiere un correo Firebase verificado para vincular la cuenta.',
                403,
            );
        }

        $user = DB::transaction(function () use ($uid, $email, $request): object {
            $uidOwner = DB::table('users')->where('firebase_uid', $uid)->lockForUpdate()->first();
            if ($uidOwner !== null) {
                return $uidOwner;
            }

            $candidate = DB::table('users')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->lockForUpdate()
                ->first();

            if ($candidate === null) {
                throw new AuthenticationFailureException(
                    'USER_NOT_PROVISIONED',
                    'Tu correo existe en Firebase, pero aún no fue registrado por un administrador del CRM.',
                    403,
                );
            }

            $currentUid = trim((string) ($candidate->firebase_uid ?? ''));
            if ($currentUid !== '' && !hash_equals($currentUid, $uid)) {
                throw new AuthenticationFailureException(
                    'FIREBASE_IDENTITY_CONFLICT',
                    'El usuario del CRM ya está vinculado a otra identidad Firebase.',
                    409,
                );
            }

            DB::table('users')->where('id', $candidate->id)->update([
                'firebase_uid' => $uid,
                'auth_provider' => $candidate->password_hash !== null ? 'HYBRID' : 'FIREBASE',
                'firebase_email_verified_at' => now(),
                'firebase_verification_last_error' => null,
                'updated_at' => now(),
            ]);

            DB::table('security_audit_logs')->insert([
                'actor_user_id' => $candidate->id,
                'partner_id' => $candidate->partner_id,
                'target_type' => 'USER',
                'target_id' => $candidate->id,
                'action' => 'FIREBASE_IDENTITY_LINKED',
                'reason' => 'Vinculación automática mediante correo verificado.',
                'ip_address' => $request->ip(),
                'metadata' => SqlValue::encode([
                    'firebase_uid_suffix' => substr($uid, -8),
                    'email' => $email,
                ]),
                'created_at' => now(),
            ]);

            return DB::table('users')->where('id', $candidate->id)->first();
        }, 3);

        $this->syncClaimsBestEffort($user);

        return $user;
    }

    private function syncClaimsBestEffort(object $user): void
    {
        if (!(bool) config('firebase.sync_custom_claims_on_login', true)) {
            return;
        }
        if (!$this->container->bound(Auth::class) || empty($user->firebase_uid)) {
            return;
        }

        try {
            /** @var Auth $auth */
            $auth = $this->container->make(Auth::class);
            $auth->setCustomUserClaims((string) $user->firebase_uid, [
                'partner_id' => $user->partner_id,
                'role' => (string) $user->role,
                'location_id' => $user->location_id,
            ]);
        } catch (\Throwable $exception) {
            // Los permisos se validan siempre en MySQL. Los claims son solo una copia auxiliar.
            Log::warning('No fue posible sincronizar los custom claims de Firebase.', [
                'user_id' => (string) $user->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
