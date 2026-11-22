<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Domain\Exceptions\AuthenticationFailureException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Auth;

final class FirebaseAuthService
{
    public function __construct(private readonly Container $container) {}

    /**
     * @return array{
     *   uid:string,
     *   email:?string,
     *   email_verified:bool,
     *   display_name:?string,
     *   phone_number:?string,
     *   claims:array<string,mixed>
     * }
     */
    public function verifyIdentityToken(string $jwt): array
    {
        if (!config('firebase.auth_enabled') || !$this->container->bound(Auth::class)) {
            throw new AuthenticationFailureException(
                'FIREBASE_NOT_CONFIGURED',
                'Firebase Authentication aún no está configurado.',
                503,
            );
        }

        try {
            /** @var Auth $auth */
            $auth = $this->container->make(Auth::class);
            $token = $auth->verifyIdToken(
                $jwt,
                (bool) config('firebase.check_revoked_tokens', true),
            );

            $claimsObject = $token->claims();
            $claims = method_exists($claimsObject, 'all') ? (array) $claimsObject->all() : [];
            $uid = trim((string) $claimsObject->get('sub'));
            if ($uid === '') {
                throw new AuthenticationFailureException('FIREBASE_UID_MISSING', 'El token Firebase no contiene un UID válido.', 401);
            }

            // La consulta del usuario ocurre únicamente durante el intercambio de sesión.
            // Permite rechazar cuentas deshabilitadas y usar el correo canónico de Firebase.
            $record = $auth->getUser($uid);
            if ((bool) ($record->disabled ?? false)) {
                throw new AuthenticationFailureException('FIREBASE_USER_DISABLED', 'La cuenta Firebase está deshabilitada.', 403);
            }

            $email = isset($record->email) && is_string($record->email)
                ? mb_strtolower(trim($record->email))
                : ($claimsObject->has('email') ? mb_strtolower(trim((string) $claimsObject->get('email'))) : null);
            $email = $email !== '' ? $email : null;

            $emailVerified = (bool) ($record->emailVerified
                ?? ($claimsObject->has('email_verified') ? $claimsObject->get('email_verified') : false));

            if ((bool) config('firebase.require_email_verified', true) && ($email === null || !$emailVerified)) {
                throw new AuthenticationFailureException(
                    'FIREBASE_EMAIL_NOT_VERIFIED',
                    'Debes verificar tu correo en Firebase antes de ingresar al CRM.',
                    403,
                );
            }

            return [
                'uid' => $uid,
                'email' => $email,
                'email_verified' => $emailVerified,
                'display_name' => isset($record->displayName) && is_string($record->displayName) ? $record->displayName : null,
                'phone_number' => isset($record->phoneNumber) && is_string($record->phoneNumber) ? $record->phoneNumber : null,
                'claims' => $claims,
            ];
        } catch (AuthenticationFailureException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::warning('Firebase ID token rechazado.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw new AuthenticationFailureException(
                'FIREBASE_TOKEN_INVALID',
                'Token Firebase inválido, expirado o revocado.',
                401,
            );
        }
    }
}
