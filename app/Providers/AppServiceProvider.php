<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request): Limit =>
            Limit::perMinute(10)->by('login:'.mb_strtolower((string) $request->input('email')).'|'.$request->ip())
        );

        RateLimiter::for('developer-console', fn (Request $request): Limit =>
            Limit::perMinute(60)->by('developer-console:'.($request->attributes->get('user_context')?->userId ?? $request->ip()))
        );

        RateLimiter::for('developer-ai', fn (Request $request): Limit =>
            Limit::perMinute(3)->by('developer-ai:'.($request->attributes->get('user_context')?->userId ?? $request->ip()))
        );

        RateLimiter::for('iot', function (Request $request): array {
            $mac = strtoupper(trim((string) $request->header('X-Device-MAC')));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute((int) env('IOT_MAX_REQUESTS_PER_IP_PER_MINUTE', 60))
                    ->by('iot-ip:'.$ip),
                Limit::perMinute((int) env('IOT_MAX_REQUESTS_PER_MINUTE', 10))
                    ->by('iot-device:'.($mac !== '' ? $mac : $ip)),
            ];
        });
    }
}
