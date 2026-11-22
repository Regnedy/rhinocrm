<?php

namespace App\Modules\Integrations\Whatsapp\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Integrations\Whatsapp\Contracts\WhatsappChannelResolverInterface;
use App\Modules\Integrations\Whatsapp\DTOs\PartnerWhatsappChannelDTO;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PartnerWhatsappChannelResolver implements WhatsappChannelResolverInterface
{
    public function __construct(private readonly Container $container) {}

    public function resolve(?string $partnerId = null): PartnerWhatsappChannelDTO
    {
        $partnerId = trim((string) ($partnerId ?? $this->contextPartnerId()));
        $channel = $this->resolveConfigured($partnerId);
        if (!$channel->isActive()) {
            throw new RuntimeException('El canal de WhatsApp del socio no está activo.', 409);
        }
        return $channel;
    }

    public function resolveConfigured(string $partnerId): PartnerWhatsappChannelDTO
    {
        $partnerId = trim($partnerId);
        if ($partnerId === '') {
            throw new RuntimeException('No se pudo determinar el socio emisor de WhatsApp.', 409);
        }

        $row = DB::table('partner_whatsapp_channels')
            ->where('partner_id', $partnerId)
            ->first();
        if ($row === null) {
            throw new RuntimeException('El socio no tiene WhatsApp Business configurado en RHINO.', 409);
        }

        $expiresAt = $row->access_token_expires_at ?? null;
        if ((string) ($row->connection_method ?? 'MANUAL') === 'EMBEDDED' && $expiresAt !== null) {
            try {
                if (CarbonImmutable::parse((string) $expiresAt)->isPast()) {
                    throw new RuntimeException('La autorización de Meta expiró. Reconecta WhatsApp desde Organización.', 409);
                }
            } catch (RuntimeException $e) {
                throw $e;
            } catch (\Throwable) {
                throw new RuntimeException('La fecha de expiración de WhatsApp no es válida. Reconecta el canal.', 409);
            }
        }

        try {
            $token = Crypt::decryptString((string) $row->access_token_ciphertext);
        } catch (\Throwable) {
            throw new RuntimeException('No se pudo descifrar la credencial de WhatsApp del socio.', 500);
        }
        if (trim($token) === '' || trim((string) $row->phone_number_id) === '') {
            throw new RuntimeException('La configuración de WhatsApp del socio está incompleta.', 409);
        }

        return new PartnerWhatsappChannelDTO(
            partnerId: (string) $row->partner_id,
            phoneNumberId: (string) $row->phone_number_id,
            accessToken: $token,
            wabaId: $row->waba_id !== null ? (string) $row->waba_id : null,
            displayPhoneNumber: $row->display_phone_number !== null ? (string) $row->display_phone_number : null,
            pdfTemplateName: $row->pdf_template_name !== null ? (string) $row->pdf_template_name : null,
            imagesTemplateName: $row->images_template_name !== null ? (string) $row->images_template_name : null,
            templateLanguage: trim((string) ($row->template_language ?? '')) ?: 'es_MX',
            status: (string) $row->status,
        );
    }

    public function summary(string $partnerId): array
    {
        $row = DB::table('partner_whatsapp_channels')->where('partner_id', $partnerId)->first();
        if ($row === null) {
            return [
                'configured' => false,
                'active' => false,
                'status' => 'NOT_CONFIGURED',
                'pdf_template_configured' => false,
                'images_template_configured' => false,
            ];
        }

        return [
            'configured' => trim((string) $row->phone_number_id) !== '' && trim((string) $row->access_token_ciphertext) !== '',
            'active' => (string) $row->status === 'ACTIVE',
            'status' => (string) $row->status,
            'connection_method' => (string) ($row->connection_method ?? 'MANUAL'),
            'access_token_expires_at' => $row->access_token_expires_at ?? null,
            'display_phone_number' => $row->display_phone_number !== null ? (string) $row->display_phone_number : null,
            'phone_number_id' => (string) $row->phone_number_id,
            'waba_id' => $row->waba_id !== null ? (string) $row->waba_id : null,
            'pdf_template_name' => $row->pdf_template_name !== null ? (string) $row->pdf_template_name : null,
            'images_template_name' => $row->images_template_name !== null ? (string) $row->images_template_name : null,
            'template_language' => trim((string) ($row->template_language ?? '')) ?: 'es_MX',
            'pdf_template_configured' => trim((string) ($row->pdf_template_name ?? '')) !== '',
            'images_template_configured' => trim((string) ($row->images_template_name ?? '')) !== '',
        ];
    }

    private function contextPartnerId(): ?string
    {
        if (!$this->container->bound(UserContextDTO::class)) {
            return null;
        }
        $context = $this->container->make(UserContextDTO::class);
        return $context instanceof UserContextDTO ? $context->partnerId : null;
    }
}
