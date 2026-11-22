<?php

namespace App\Http\Middleware;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\AuthenticationFailureException;
use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Modules\Auth\Services\AccessTokenService;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyTenantAccess
{
    public function __construct(
        private readonly AccessTokenService $authService,
        private readonly Container $container,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            return $this->error('AUTH_TOKEN_MISSING', 'Se requiere un token Bearer.', 401);
        }

        try {
            $context = $this->authService->verify($token);
            $requestedPartner = $request->route('partner') ?? $request->route('partnerId') ?? $request->header('X-Partner-ID');
            if ($requestedPartner && !$context->isSuperAdmin() && $context->partnerId !== (string) $requestedPartner) {
                throw new TenantViolationException($context->userId, (string) $requestedPartner);
            }

            $this->container->forgetInstance(UserContextDTO::class);
            $this->container->instance(UserContextDTO::class, $context);
            $request->attributes->set('user_context', $context);
            return $next($request);
        } catch (AuthenticationFailureException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), (int) $exception->getCode());
        } catch (TenantViolationException $exception) {
            return $this->error('TENANT_ACCESS_DENIED', $exception->getMessage(), 403);
        } catch (\Throwable) {
            return $this->error('AUTH_TOKEN_INVALID', 'Token inválido o expirado.', 401);
        }
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
    }
}
