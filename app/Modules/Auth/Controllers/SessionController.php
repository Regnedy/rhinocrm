<?php

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Domain\Exceptions\AuthenticationFailureException;
use App\Modules\Auth\Requests\FirebaseExchangeRequest;
use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Services\ApiSessionService;
use App\Modules\Auth\Services\FirebaseAuthService;
use App\Modules\Auth\Services\FirebaseIdentityLinkService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class SessionController
{
    use ApiResponse;

    public function login(LoginRequest $request, ApiSessionService $sessions): JsonResponse
    {
        if ((string) config('rhino.auth.driver', 'local') === 'firebase') {
            throw new AuthenticationFailureException(
                'LOCAL_LOGIN_DISABLED',
                'El inicio de sesión local está deshabilitado. Utiliza Firebase Authentication.',
                403,
            );
        }

        $user = DB::table('users')->whereRaw('LOWER(email) = ?', [mb_strtolower($request->string('email')->toString())])->first();
        if ($user === null || $user->password_hash === null || !Hash::check($request->string('password')->toString(), (string) $user->password_hash)) {
            throw new AuthenticationFailureException('INVALID_CREDENTIALS', 'Correo o contraseña incorrectos.');
        }

        return $this->successResponse($sessions->create((string) $user->id, $request->validated(), $request), 'Sesión iniciada.');
    }

    public function exchange(
        FirebaseExchangeRequest $request,
        FirebaseAuthService $firebase,
        FirebaseIdentityLinkService $links,
        ApiSessionService $sessions,
    ): JsonResponse {
        $jwt = $request->bearerToken();
        if ($jwt === null || $jwt === '') {
            throw new AuthenticationFailureException('AUTH_TOKEN_MISSING', 'Se requiere el token Firebase.', 401);
        }

        $identity = $firebase->verifyIdentityToken($jwt);
        $user = $links->resolveOrLink($identity, $request);

        $session = $sessions->create((string) $user->id, $request->validated(), $request);
        $session['auth_provider'] = 'FIREBASE';
        $session['user_id'] = (string) $user->id;

        return $this->successResponse($session, 'Token Firebase intercambiado.');
    }

    public function logout(Request $request, ApiSessionService $sessions): JsonResponse
    {
        $context = $request->attributes->get('user_context');
        $token = (string) $request->bearerToken();
        $sessions->revokeToken($token, (string) $context->userId);
        return $this->successResponse([], 'Sesión cerrada.');
    }
}
