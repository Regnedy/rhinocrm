<?php

namespace App\Modules\DeveloperConsole\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DeveloperConsolePageController
{
    public function show(Request $request): Response
    {
        if (!config('developer_console.enabled', false)) {
            abort(404);
        }

        $allowedIps = array_values(array_filter((array) config('developer_console.allowed_ips', [])));
        if ($allowedIps !== [] && !in_array((string) $request->ip(), $allowedIps, true)) {
            abort(403, 'Dirección IP no autorizada.');
        }

        $nonce = base64_encode(random_bytes(24));
        return response()
            ->view('developer-console', ['nonce' => $nonce])
            ->header('Content-Security-Policy', "default-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; connect-src 'self'; img-src 'self' data:; style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'")
            ->header('X-Frame-Options', 'DENY')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store, private');
    }
}
