<?php

namespace App\Http\Middleware;

use App\Modules\Auth\DTOs\UserContextDTO;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureDeveloperConsoleAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('developer_console.enabled', false)) {
            return $this->error('DEVELOPER_CONSOLE_DISABLED', 'La consola de mantenimiento está deshabilitada.', 404);
        }

        /** @var UserContextDTO|null $context */
        $context = $request->attributes->get('user_context');
        if (!$context instanceof UserContextDTO || !$context->isSuperAdmin()) {
            return $this->error('SUPER_ADMIN_REQUIRED', 'Solo SUPER_ADMIN puede utilizar la consola de mantenimiento.', 403);
        }

        $allowedIps = array_values(array_filter((array) config('developer_console.allowed_ips', [])));
        if ($allowedIps !== [] && !in_array((string) $request->ip(), $allowedIps, true)) {
            return $this->error('DEVELOPER_CONSOLE_IP_DENIED', 'Esta dirección IP no está autorizada.', 403);
        }

        return $next($request);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }
}
