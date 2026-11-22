<?php

namespace App\Modules\Auth\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\UserNotFound;

final class HybridIdentityService
{
    public function __construct(private readonly Container $container) {}

    /** @return array{id:string,provider:string,password_hash:?string,firebase_uid:?string,created:bool,email_verified:bool,email:string} */
    public function create(array $data, array $claims): array
    {
        $driver = (string) config('rhino.auth.driver', 'local');
        $email = mb_strtolower(trim((string) $data['email']));

        if ($this->firebaseEnabled($driver)) {
            /** @var Auth $auth */
            $auth = $this->container->make(Auth::class);
            $created = false;

            try {
                $record = $auth->getUserByEmail($email);
                $owner = DB::table('users')
                    ->where('firebase_uid', (string) $record->uid)
                    ->whereNull('deleted_at')
                    ->first();

                if ($owner !== null && mb_strtolower((string) $owner->email) !== $email) {
                    throw new \RuntimeException('La identidad Firebase ya está vinculada a otra cuenta Rhino.', 409);
                }

                // Sincroniza siempre la contraseña temporal y el nombre. Esto corrige
                // cuentas huérfanas creadas durante altas incompletas.
                $record = $auth->updateUser((string) $record->uid, [
                    'password' => (string) $data['password'],
                    'displayName' => (string) $data['name'],
                    'disabled' => false,
                ]);
            } catch (UserNotFound) {
                $record = $auth->createUser([
                    'email' => $email,
                    'emailVerified' => false,
                    'password' => (string) $data['password'],
                    'displayName' => (string) $data['name'],
                    'disabled' => false,
                ]);
                $created = true;
            }

            $compactClaims = array_intersect_key($claims, array_flip([
                'partner_id',
                'role',
                'location_id',
            ]));
            $auth->setCustomUserClaims((string) $record->uid, $compactClaims);

            return [
                'id' => 'USR_'.strtoupper((string) Str::ulid()),
                'provider' => $driver === 'hybrid' ? 'HYBRID' : 'FIREBASE',
                'password_hash' => $driver === 'hybrid' ? Hash::make((string) $data['password']) : null,
                'firebase_uid' => (string) $record->uid,
                'created' => $created,
                'email_verified' => (bool) ($record->emailVerified ?? false),
                'email' => $email,
            ];
        }

        return [
            'id' => 'USR_'.strtoupper((string) Str::ulid()),
            'provider' => 'LOCAL',
            'password_hash' => Hash::make((string) $data['password']),
            'firebase_uid' => null,
            'created' => false,
            'email_verified' => true,
            'email' => $email,
        ];
    }

    /** @return array{firebase_enabled:bool,email_verified:bool,sent:bool,sent_at:?string} */
    public function sendEmailVerification(string $email): array
    {
        if (!$this->firebaseEnabled()) {
            return ['firebase_enabled' => false, 'email_verified' => true, 'sent' => false, 'sent_at' => null];
        }

        /** @var Auth $auth */
        $auth = $this->container->make(Auth::class);
        $email = mb_strtolower(trim($email));
        $record = $auth->getUserByEmail($email);

        if ((bool) ($record->emailVerified ?? false)) {
            return ['firebase_enabled' => true, 'email_verified' => true, 'sent' => false, 'sent_at' => null];
        }

        $auth->sendEmailVerificationLink($email, $this->actionCodeSettings(), 'es');

        return [
            'firebase_enabled' => true,
            'email_verified' => false,
            'sent' => true,
            'sent_at' => now()->toIso8601String(),
        ];
    }

    public function getEmailVerificationLink(string $email): string
    {
        if (!$this->firebaseEnabled()) {
            throw new \RuntimeException('Firebase Authentication no está habilitado.', 422);
        }

        /** @var Auth $auth */
        $auth = $this->container->make(Auth::class);
        $email = mb_strtolower(trim($email));
        $record = $auth->getUserByEmail($email);

        if ((bool) ($record->emailVerified ?? false)) {
            throw new \RuntimeException('El correo ya está verificado.', 409);
        }

        return $auth->getEmailVerificationLink($email, $this->actionCodeSettings(), 'es');
    }

    /** @return array{firebase_enabled:bool,exists:bool,uid:?string,email_verified:bool,disabled:bool} */
    public function status(?string $firebaseUid, string $email): array
    {
        if (!$this->firebaseEnabled()) {
            return [
                'firebase_enabled' => false,
                'exists' => false,
                'uid' => null,
                'email_verified' => true,
                'disabled' => false,
            ];
        }

        /** @var Auth $auth */
        $auth = $this->container->make(Auth::class);
        try {
            $record = $firebaseUid !== null && trim($firebaseUid) !== ''
                ? $auth->getUser(trim($firebaseUid))
                : $auth->getUserByEmail(mb_strtolower(trim($email)));
        } catch (UserNotFound) {
            return [
                'firebase_enabled' => true,
                'exists' => false,
                'uid' => null,
                'email_verified' => false,
                'disabled' => false,
            ];
        }

        return [
            'firebase_enabled' => true,
            'exists' => true,
            'uid' => (string) $record->uid,
            'email_verified' => (bool) ($record->emailVerified ?? false),
            'disabled' => (bool) ($record->disabled ?? false),
        ];
    }

    public function changePassword(string $firebaseUid, string $password, ?string $displayName = null): void
    {
        if ($firebaseUid === '' || !$this->firebaseEnabled()) {
            return;
        }

        /** @var Auth $auth */
        $auth = $this->container->make(Auth::class);
        $properties = ['password' => $password, 'disabled' => false];
        if ($displayName !== null && trim($displayName) !== '') {
            $properties['displayName'] = trim($displayName);
        }
        $auth->updateUser($firebaseUid, $properties);
        $auth->revokeRefreshTokens($firebaseUid);
    }

    public function delete(array $identity): void
    {
        if (($identity['provider'] ?? null) === 'LOCAL'
            || !($identity['created'] ?? false)
            || !$this->container->bound(Auth::class)
            || empty($identity['firebase_uid'])) {
            return;
        }

        try {
            /** @var Auth $auth */
            $auth = $this->container->make(Auth::class);
            $auth->deleteUser((string) $identity['firebase_uid']);
        } catch (\Throwable) {
            // Compensación best-effort; la operación original se conserva en logs.
        }
    }

    public function suspendFirebase(string $firebaseUid): void
    {
        if ($firebaseUid === '' || !$this->firebaseEnabled()) {
            return;
        }
        /** @var Auth $auth */
        $auth = $this->container->make(Auth::class);
        $auth->disableUser($firebaseUid);
        $auth->revokeRefreshTokens($firebaseUid);
    }

    public function activateFirebase(string $firebaseUid): void
    {
        if ($firebaseUid === '' || !$this->firebaseEnabled()) {
            return;
        }
        /** @var Auth $auth */
        $auth = $this->container->make(Auth::class);
        $auth->enableUser($firebaseUid);
    }

    private function firebaseEnabled(?string $driver = null): bool
    {
        $driver ??= (string) config('rhino.auth.driver', 'local');

        return in_array($driver, ['firebase', 'hybrid'], true)
            && (bool) config('firebase.auth_enabled')
            && $this->container->bound(Auth::class);
    }

    /** @return array{continueUrl:string,handleCodeInApp:bool} */
    private function actionCodeSettings(): array
    {
        $continueUrl = trim((string) config('firebase.verification_continue_url', config('app.url')));
        if ($continueUrl === '') {
            $continueUrl = (string) config('app.url');
        }

        return [
            'continueUrl' => rtrim($continueUrl, '/'),
            'handleCodeInApp' => false,
        ];
    }
}
