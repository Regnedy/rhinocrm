<?php

declare(strict_types=1);

use App\Modules\Integrations\Whatsapp\Services\MetaEmbeddedSignupClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function embeddedHttpOk(bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

config([
    'services.meta.api_url' => 'https://graph.facebook.com/v25.0',
    'services.meta.app_id' => 'APP_123',
    'services.meta.app_secret' => 'SECRET_456',
]);

$requests = [];
Http::fake(function (Request $request) use (&$requests) {
    $requests[] = $request;
    if (str_ends_with($request->url(), '/oauth/access_token')) {
        return Http::response(['access_token' => 'USER_TOKEN', 'token_type' => 'bearer'], 200);
    }
    if (str_contains($request->url(), '/debug_token')) {
        return Http::response(['data' => [
            'app_id' => 'APP_123',
            'type' => 'USER',
            'is_valid' => true,
            'expires_at' => 1790000000,
            'user_id' => 'META_USER_1',
            'scopes' => ['whatsapp_business_management', 'whatsapp_business_messaging'],
            'granular_scopes' => [[
                'scope' => 'whatsapp_business_management',
                'target_ids' => ['WABA_A'],
            ]],
        ]], 200);
    }
    if (str_contains($request->url(), '/WABA_A/phone_numbers')) {
        return Http::response(['data' => [[
            'id' => 'PHONE_A',
            'display_phone_number' => '+52 662 380 1127',
            'verified_name' => 'Socio Demo',
            'quality_rating' => 'GREEN',
        ]]], 200);
    }
    if (str_ends_with($request->url(), '/WABA_A/subscribed_apps')) {
        return Http::response(['success' => true], 200);
    }
    return Http::response(['error' => ['message' => 'Unexpected test URL']], 500);
});

$client = new MetaEmbeddedSignupClient();
$token = $client->exchangeCode('CODE_ABC');
$inspection = $client->inspectToken($token);
$phones = $client->phoneNumbers('WABA_A', $token);
$client->subscribeWaba('WABA_A', $token);

embeddedHttpOk($token === 'USER_TOKEN', 'Debe intercambiar code por access token.');
embeddedHttpOk(($inspection['app_id'] ?? null) === 'APP_123', 'Debe inspeccionar el token.');
embeddedHttpOk(($phones[0]['id'] ?? null) === 'PHONE_A', 'Debe recuperar los teléfonos de la WABA.');
embeddedHttpOk(count($requests) === 4, 'Deben realizarse cuatro requests Graph.');

$oauth = $requests[0];
embeddedHttpOk(($oauth->data()['client_id'] ?? null) === 'APP_123', 'OAuth debe enviar App ID.');
embeddedHttpOk(($oauth->data()['client_secret'] ?? null) === 'SECRET_456', 'OAuth debe enviar App Secret solo servidor-servidor.');
embeddedHttpOk(($oauth->data()['code'] ?? null) === 'CODE_ABC', 'OAuth debe intercambiar el code exacto.');

$debug = $requests[1];
embeddedHttpOk(str_contains(implode(' ', $debug->header('Authorization')), 'APP_123|SECRET_456'), 'debug_token debe autenticarse sin exponer secreto al navegador.');
embeddedHttpOk(str_contains($debug->url(), 'input_token=USER_TOKEN'), 'debug_token debe inspeccionar el token retornado.');

$phonesRequest = $requests[2];
embeddedHttpOk(str_contains(implode(' ', $phonesRequest->header('Authorization')), 'USER_TOKEN'), 'Consulta de teléfonos debe usar token autorizado del negocio.');

$subscribe = $requests[3];
embeddedHttpOk($subscribe->method() === 'POST', 'Suscripción WABA debe ser POST.');
embeddedHttpOk(str_contains(implode(' ', $subscribe->header('Authorization')), 'USER_TOKEN'), 'Suscripción debe usar token autorizado.');

fwrite(STDOUT, "PASS: Meta Embedded Signup HTTP contract\n");
