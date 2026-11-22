<?php

namespace App\Modules\Web\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class CrmPageController
{
    public function show(Request $request): Response|RedirectResponse
    {
        if (!is_file((string) config('rhino.installed_lock', storage_path('app/installed.lock')))) {
            return redirect('/install.php');
        }

        return response()
            ->view('crm', [
                'version' => (string) config('rhino.version', 'development'),
                'defaultDialCode' => (string) config('rhino.region.default_country_dial_code', '52'),
                'defaultCountryIso' => (string) config('rhino.region.default_country_iso', 'MX'),
                'assetVersion' => (string) max(
                    (int) @filemtime(public_path('assets/rhino-crm.css')),
                    (int) @filemtime(public_path('assets/rhino-crm.js')),
                ),
            ])
            ->header('Content-Security-Policy', "default-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; connect-src 'self' https://graph.facebook.com https://www.facebook.com https://web.facebook.com; img-src 'self' data: https://www.facebook.com; style-src 'self' 'unsafe-inline'; script-src 'self' https://connect.facebook.net; frame-src https://www.facebook.com https://web.facebook.com; worker-src 'self'; manifest-src 'self'; font-src 'self'")
            ->header('X-Frame-Options', 'DENY')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', 'same-origin')
            ->header('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->header('Cache-Control', 'no-store, private');
    }
}
