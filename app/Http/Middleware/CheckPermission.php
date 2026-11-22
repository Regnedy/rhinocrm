<?php

namespace App\Http\Middleware;

use App\Modules\Auth\DTOs\UserContextDTO;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        /** @var UserContextDTO $context */
        $context = app(UserContextDTO::class);
        if (!$context->hasPermission($permission)) {
            return new JsonResponse([
                'success' => false,
                'error' => ['code' => 'PERMISSION_DENIED', 'message' => "Se requiere el permiso: {$permission}"],
            ], 403);
        }
        return $next($request);
    }
}
