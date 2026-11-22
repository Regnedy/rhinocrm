<?php

namespace App\Modules\Integrations\Whatsapp\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MetaEmbeddedSignupClient
{
    public function exchangeCode(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            throw new RuntimeException('Meta no devolvió un código de autorización.', 422);
        }

        $response = Http::asForm()
            ->timeout(20)
            ->retry(1, 500)
            ->post($this->url('/oauth/access_token'), [
                'client_id' => $this->appId(),
                'client_secret' => $this->appSecret(),
                'code' => $code,
            ]);

        $this->assertSuccessful($response, 'No se pudo intercambiar la autorización de Meta.');
        $token = trim((string) $response->json('access_token'));
        if ($token === '') {
            throw new RuntimeException('Meta no devolvió una credencial utilizable.', 409);
        }
        return $token;
    }

    /** @return array<string,mixed> */
    public function inspectToken(string $accessToken): array
    {
        $response = Http::withToken($this->appAccessToken())
            ->timeout(20)
            ->retry(1, 500)
            ->get($this->url('/debug_token'), ['input_token' => $accessToken]);

        $this->assertSuccessful($response, 'No se pudo verificar la autorización de Meta.');
        $data = $response->json('data');
        if (!is_array($data) || ($data['is_valid'] ?? false) !== true) {
            throw new RuntimeException('La autorización devuelta por Meta no es válida.', 409);
        }
        return $data;
    }

    /** @return list<array<string,mixed>> */
    public function phoneNumbers(string $wabaId, string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->timeout(20)
            ->retry(1, 500)
            ->get($this->url('/'.rawurlencode($wabaId).'/phone_numbers'), [
                'fields' => 'id,display_phone_number,verified_name,quality_rating,code_verification_status',
            ]);

        $this->assertSuccessful($response, 'No se pudieron consultar los números de WhatsApp autorizados.');
        $rows = $response->json('data');
        if (!is_array($rows)) {
            return [];
        }
        return array_values(array_filter($rows, 'is_array'));
    }

    public function subscribeWaba(string $wabaId, string $accessToken): void
    {
        $response = Http::withToken($accessToken)
            ->timeout(20)
            ->retry(1, 500)
            ->post($this->url('/'.rawurlencode($wabaId).'/subscribed_apps'));

        $this->assertSuccessful($response, 'No se pudo suscribir RHINO a los eventos de la cuenta de WhatsApp.');
        if ($response->json('success') !== true) {
            throw new RuntimeException('Meta no confirmó la suscripción del webhook.', 409);
        }
    }

    public function appId(): string
    {
        $value = trim((string) config('services.meta.app_id'));
        if ($value === '') {
            throw new RuntimeException('META_APP_ID no está configurado en RHINO.', 503);
        }
        return $value;
    }

    public function embeddedSignupConfigId(): string
    {
        $value = trim((string) config('services.meta.embedded_signup_config_id'));
        if ($value === '') {
            throw new RuntimeException('META_EMBEDDED_SIGNUP_CONFIG_ID no está configurado en RHINO.', 503);
        }
        return $value;
    }

    private function appSecret(): string
    {
        $value = trim((string) config('services.meta.app_secret'));
        if ($value === '') {
            throw new RuntimeException('META_APP_SECRET no está configurado en RHINO.', 503);
        }
        return $value;
    }

    private function appAccessToken(): string
    {
        return $this->appId().'|'.$this->appSecret();
    }

    private function url(string $path): string
    {
        $base = rtrim((string) config('services.meta.api_url', 'https://graph.facebook.com/v25.0'), '/');
        return $base.'/'.ltrim($path, '/');
    }

    private function assertSuccessful(Response $response, string $message): void
    {
        if ($response->successful()) {
            return;
        }

        $metaMessage = trim((string) $response->json('error.message'));
        $suffix = $metaMessage !== '' ? ' Meta: '.mb_substr($metaMessage, 0, 300) : '';
        throw new RuntimeException($message.' (HTTP '.$response->status().').'.$suffix, 409);
    }
}
