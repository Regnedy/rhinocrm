<?php

namespace App\Modules\Integrations\Whatsapp\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class PartnerWhatsappChannelService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly PartnerWhatsappChannelResolver $resolver,
    ) {}

    /** @return array<string,mixed> */
    public function show(?string $requestedPartnerId = null): array
    {
        $partnerId = $this->authorizedPartnerId($requestedPartnerId, false);
        $row = DB::table('partner_whatsapp_channels')->where('partner_id', $partnerId)->first();
        if ($row === null) {
            return [
                'partner_id' => $partnerId,
                'configured' => false,
                'token_configured' => false,
                'status' => 'NOT_CONFIGURED',
                'template_language' => 'es_MX',
            ];
        }

        return $this->serialize($row);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function save(array $data, ?string $requestedPartnerId = null): array
    {
        if (!$this->context->isSuperAdmin()) {
            throw new RuntimeException('La configuración manual está reservada para SUPER_ADMIN. Usa Conectar con Meta.', 403);
        }
        $partnerId = $this->authorizedPartnerId($requestedPartnerId, true);
        $existing = DB::table('partner_whatsapp_channels')->where('partner_id', $partnerId)->first();
        $token = trim((string) ($data['access_token'] ?? ''));
        if ($existing === null && $token === '') {
            throw new RuntimeException('El token de acceso es obligatorio al conectar WhatsApp por primera vez.', 422);
        }

        $phoneNumberId = trim((string) ($data['phone_number_id'] ?? ''));
        if ($phoneNumberId === '') {
            throw new RuntimeException('Phone Number ID es obligatorio.', 422);
        }
        if (DB::table('partner_whatsapp_channels')
            ->where('phone_number_id', $phoneNumberId)
            ->where('partner_id', '<>', $partnerId)
            ->exists()) {
            throw new RuntimeException('Ese Phone Number ID ya está conectado a otro socio.', 409);
        }

        $values = [
            'display_phone_number' => $this->nullable($data['display_phone_number'] ?? null),
            'phone_number_id' => $phoneNumberId,
            'waba_id' => $this->nullable($data['waba_id'] ?? null),
            'pdf_template_name' => $this->nullable($data['pdf_template_name'] ?? null),
            'images_template_name' => $this->nullable($data['images_template_name'] ?? null),
            'template_language' => trim((string) ($data['template_language'] ?? '')) ?: 'es_MX',
            'status' => 'ACTIVE',
            'connection_method' => 'MANUAL',
            'meta_user_id' => null,
            'meta_app_id' => null,
            'access_token_expires_at' => null,
            'embedded_signup_at' => null,
            'webhook_subscribed_at' => null,
            'last_error' => null,
            'updated_by_user_id' => $this->context->userId,
            'updated_at' => now(),
        ];
        if ($token !== '') {
            $values['access_token_ciphertext'] = Crypt::encryptString($token);
            $values['access_token_updated_at'] = now();
        }

        if ($existing === null) {
            $values = array_merge($values, [
                'id' => 'WACH_'.strtoupper((string) Str::ulid()),
                'partner_id' => $partnerId,
                'created_by_user_id' => $this->context->userId,
                'created_at' => now(),
            ]);
            DB::table('partner_whatsapp_channels')->insert($values);
        } else {
            DB::table('partner_whatsapp_channels')->where('partner_id', $partnerId)->update($values);
        }

        $this->audit($partnerId, 'PARTNER_WHATSAPP_CHANNEL_UPDATED', [
            'phone_number_id' => $phoneNumberId,
            'waba_id' => $values['waba_id'],
            'display_phone_number' => $values['display_phone_number'],
            'token_rotated' => $token !== '',
            'pdf_template_configured' => $values['pdf_template_name'] !== null,
            'images_template_configured' => $values['images_template_name'] !== null,
        ]);

        return $this->show($partnerId);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function saveEmbeddedConnection(array $data, ?string $requestedPartnerId = null): array
    {
        $partnerId = $this->authorizedPartnerId($requestedPartnerId, true);
        $token = trim((string) ($data['access_token'] ?? ''));
        $phoneNumberId = trim((string) ($data['phone_number_id'] ?? ''));
        $wabaId = trim((string) ($data['waba_id'] ?? ''));
        if ($token === '' || $phoneNumberId === '' || $wabaId === '') {
            throw new RuntimeException('Meta devolvió una conexión incompleta.', 422);
        }
        if (DB::table('partner_whatsapp_channels')
            ->where('phone_number_id', $phoneNumberId)
            ->where('partner_id', '<>', $partnerId)
            ->exists()) {
            throw new RuntimeException('Ese número de WhatsApp ya está conectado a otro socio.', 409);
        }

        $existing = DB::table('partner_whatsapp_channels')->where('partner_id', $partnerId)->first();
        $values = [
            'display_phone_number' => $this->nullable($data['display_phone_number'] ?? null),
            'phone_number_id' => $phoneNumberId,
            'waba_id' => $wabaId,
            'access_token_ciphertext' => Crypt::encryptString($token),
            'access_token_updated_at' => now(),
            'pdf_template_name' => $this->nullable($data['pdf_template_name'] ?? null),
            'images_template_name' => $this->nullable($data['images_template_name'] ?? null),
            'template_language' => trim((string) ($data['template_language'] ?? '')) ?: 'es_MX',
            'status' => 'ACTIVE',
            'connection_method' => 'EMBEDDED',
            'meta_user_id' => $this->nullable($data['meta_user_id'] ?? null),
            'meta_app_id' => $this->nullable($data['meta_app_id'] ?? null),
            'access_token_expires_at' => $data['access_token_expires_at'] ?? null,
            'embedded_signup_at' => now(),
            'webhook_subscribed_at' => now(),
            'last_error' => null,
            'updated_by_user_id' => $this->context->userId,
            'updated_at' => now(),
        ];

        if ($existing === null) {
            $values = array_merge($values, [
                'id' => 'WACH_'.strtoupper((string) Str::ulid()),
                'partner_id' => $partnerId,
                'created_by_user_id' => $this->context->userId,
                'created_at' => now(),
            ]);
            DB::table('partner_whatsapp_channels')->insert($values);
        } else {
            DB::table('partner_whatsapp_channels')->where('partner_id', $partnerId)->update($values);
        }

        $this->audit($partnerId, 'PARTNER_WHATSAPP_EMBEDDED_SIGNUP_COMPLETED', [
            'phone_number_id' => $phoneNumberId,
            'waba_id' => $wabaId,
            'display_phone_number' => $values['display_phone_number'],
            'meta_user_id' => $values['meta_user_id'],
            'meta_app_id' => $values['meta_app_id'],
            'token_expires_at' => $values['access_token_expires_at'],
            'webhook_subscribed' => true,
            'verified_name' => $this->nullable($data['verified_name'] ?? null),
            'quality_rating' => $this->nullable($data['quality_rating'] ?? null),
        ]);

        return $this->show($partnerId);
    }

    /** @return array<string,mixed> */
    public function testConnection(?string $requestedPartnerId = null): array
    {
        $partnerId = $this->authorizedPartnerId($requestedPartnerId, true);
        $channel = $this->resolver->resolveConfigured($partnerId);
        $url = rtrim((string) config('services.meta.api_url'), '/').'/'.rawurlencode($channel->phoneNumberId);

        $response = Http::withToken($channel->accessToken)
            ->timeout(20)
            ->retry(1, 500)
            ->get($url, ['fields' => 'display_phone_number,verified_name']);

        if (!$response->successful()) {
            $message = 'Meta rechazó la prueba de conexión (HTTP '.$response->status().').';
            DB::table('partner_whatsapp_channels')->where('partner_id', $partnerId)->update([
                'status' => 'ERROR',
                'last_tested_at' => now(),
                'last_error' => $message,
                'updated_by_user_id' => $this->context->userId,
                'updated_at' => now(),
            ]);
            $this->audit($partnerId, 'PARTNER_WHATSAPP_CHANNEL_TEST_FAILED', ['http_status' => $response->status()]);
            throw new RuntimeException($message, 409);
        }

        $displayPhone = trim((string) $response->json('display_phone_number'));
        $update = [
            'status' => 'ACTIVE',
            'last_tested_at' => now(),
            'last_error' => null,
            'updated_by_user_id' => $this->context->userId,
            'updated_at' => now(),
        ];
        if ($displayPhone !== '') {
            $update['display_phone_number'] = $displayPhone;
        }
        DB::table('partner_whatsapp_channels')->where('partner_id', $partnerId)->update($update);
        $this->audit($partnerId, 'PARTNER_WHATSAPP_CHANNEL_TESTED', [
            'verified_name' => $this->nullable($response->json('verified_name')),
            'display_phone_number' => $displayPhone !== '' ? $displayPhone : null,
        ]);

        $result = $this->show($partnerId);
        $result['verified_name'] = $this->nullable($response->json('verified_name'));
        return $result;
    }

    /** @return array<string,mixed> */
    public function disable(?string $requestedPartnerId = null): array
    {
        $partnerId = $this->authorizedPartnerId($requestedPartnerId, true);
        if (!DB::table('partner_whatsapp_channels')->where('partner_id', $partnerId)->exists()) {
            throw new RuntimeException('El socio no tiene un canal WhatsApp configurado.', 404);
        }
        DB::table('partner_whatsapp_channels')->where('partner_id', $partnerId)->update([
            'status' => 'DISABLED',
            'updated_by_user_id' => $this->context->userId,
            'updated_at' => now(),
        ]);
        $this->audit($partnerId, 'PARTNER_WHATSAPP_CHANNEL_DISABLED', []);
        return $this->show($partnerId);
    }

    private function authorizedPartnerId(?string $requestedPartnerId, bool $manage): string
    {
        if ($manage && !$this->context->isSuperAdmin() && !$this->context->hasPermission('organization.whatsapp.manage')) {
            throw new RuntimeException('No tienes permiso para administrar WhatsApp de la organización.', 403);
        }
        if (!$manage && !$this->context->isSuperAdmin() && !$this->context->hasPermission('organization.read')) {
            throw new RuntimeException('No tienes permiso para consultar la organización.', 403);
        }

        $requested = trim((string) $requestedPartnerId);
        if ($this->context->isSuperAdmin()) {
            if ($requested === '') {
                throw new RuntimeException('Selecciona un socio.', 422);
            }
            return $requested;
        }

        $own = trim((string) $this->context->partnerId);
        if ($own === '') {
            throw new RuntimeException('Tu usuario no tiene un socio asociado.', 403);
        }
        if ($requested !== '' && $requested !== $own) {
            throw new RuntimeException('No puedes administrar el WhatsApp de otro socio.', 403);
        }
        return $own;
    }

    /** @return array<string,mixed> */
    private function serialize(object $row): array
    {
        return [
            'partner_id' => (string) $row->partner_id,
            'configured' => trim((string) $row->phone_number_id) !== '' && trim((string) $row->access_token_ciphertext) !== '',
            'token_configured' => trim((string) $row->access_token_ciphertext) !== '',
            'status' => (string) $row->status,
            'connection_method' => (string) ($row->connection_method ?? 'MANUAL'),
            'display_phone_number' => $row->display_phone_number !== null ? (string) $row->display_phone_number : null,
            'phone_number_id' => (string) $row->phone_number_id,
            'waba_id' => $row->waba_id !== null ? (string) $row->waba_id : null,
            'pdf_template_name' => $row->pdf_template_name !== null ? (string) $row->pdf_template_name : null,
            'images_template_name' => $row->images_template_name !== null ? (string) $row->images_template_name : null,
            'template_language' => trim((string) ($row->template_language ?? '')) ?: 'es_MX',
            'access_token_expires_at' => $row->access_token_expires_at ?? null,
            'embedded_signup_at' => $row->embedded_signup_at ?? null,
            'webhook_subscribed_at' => $row->webhook_subscribed_at ?? null,
            'last_tested_at' => $row->last_tested_at ?? null,
            'last_error' => $row->last_error !== null ? (string) $row->last_error : null,
            'updated_at' => $row->updated_at ?? null,
        ];
    }

    /** @param array<string,mixed> $metadata */
    private function audit(string $partnerId, string $action, array $metadata): void
    {
        DB::table('security_audit_logs')->insert([
            'actor_user_id' => $this->context->userId,
            'partner_id' => $partnerId,
            'target_type' => 'PARTNER_WHATSAPP_CHANNEL',
            'target_id' => $partnerId,
            'action' => $action,
            'reason' => null,
            'ip_address' => null,
            'metadata' => SqlValue::encode($metadata),
            'created_at' => now(),
        ]);
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
