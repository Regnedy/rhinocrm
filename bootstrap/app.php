<?php

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureDeveloperConsoleAccess;
use App\Http\Middleware\VerifyTenantAccess;
use App\Modules\IoT\Http\Middleware\VerifyEsp32Signature;
use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Modules\Auth\Domain\Exceptions\AuthenticationFailureException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Http\Request;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([__DIR__.'/../app/Console/Commands'])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => VerifyTenantAccess::class,
            'permission' => CheckPermission::class,
            'iot.signature' => VerifyEsp32Signature::class,
            'developer.console' => EnsureDeveloperConsoleAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (TenantViolationException $exception, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'error' => ['code' => 'TENANT_ACCESS_DENIED', 'message' => $exception->getMessage()],
            ], 403);
        });

        $exceptions->render(function (AuthenticationFailureException $exception, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()],
            ], (int) $exception->getCode());
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'Los datos enviados no son válidos.',
                    'details' => $exception->errors(),
                ],
            ], 422);
        });

        $exceptions->render(function (\Throwable $exception, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            $status = $exception instanceof HttpExceptionInterface
                ? $exception->getStatusCode()
                : (in_array((int) $exception->getCode(), [400, 401, 403, 404, 409, 422], true)
                    ? (int) $exception->getCode()
                    : 500);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => $status >= 500 ? 'INTERNAL_ERROR' : 'REQUEST_FAILED',
                    'message' => $status >= 500 && !config('app.debug')
                        ? 'Ocurrió un error interno.'
                        : $exception->getMessage(),
                ],
            ], $status);
        });
    })
    ->create();
