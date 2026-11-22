<?php

namespace App\Modules\Integrations\Whatsapp\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use Carbon\CarbonImmutable;
use RuntimeException;

final class PartnerWhatsappEmbeddedSignupService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly MetaEmbeddedSignupClient $meta,
        private readonly PartnerWhatsappChannelService $channels,
    ) {}

    /** @return array<string,mixed> */
    public function publicConfig(?string $requestedPartnerId = null): array
    {
        $partnerId = $this->authorizedPartnerId($requestedPartnerId);
        $appId = trim((string) config('services.meta.app_id'));
        $configId = trim((string) config('services.meta.embedded_signup_config_id'));
        $hasSecret = trim((string) config('services.meta.app_secret')) !== '';
        $enabledFlag = filter_var(config('services.meta.embedded_signup_enabled', false), FILTER_VALIDATE_BOOL);
        $missing = [];
        if ($appId === '') $missing[] = 'META_APP_ID';
        if ($configId === '') $missing[] = 'META_EMBEDDED_SIGNUP_CONFIG_ID';
        if (!$hasSecret) $missing[] = 'META_APP_SECRET';
        if (!$enabledFlag) $missing[] = 'META_EMBEDDED_SIGNUP_ENABLED';

        return [
            'partner_id' => $partnerId,
            'enabled' => $missing === [],
            'app_id' => $appId !== '' ? $appId : null,
            'config_id' => $configId !== '' ? $configId : null,
            'graph_version' => $this->graphVersion(),
            'missing' => $missing,
        ];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function complete(array $data, ?string $requestedPartnerId = null): array
    {
        $partnerId = $this->authorizedPartnerId($requestedPartnerId);
        $config = $this->publicConfig($partnerId);
        if (($config['enabled'] ?? false) !== true) {
            throw new RuntimeException('Embedded Signup todavía no está habilitado en la configuración de RHINO.', 503);
        }

        $accessToken = $this->meta->exchangeCode((string) ($data['code'] ?? ''));
        $inspection = $this->meta->inspectToken($accessToken);
        $this->validateAppAndScopes($inspection);

        $wabaId = $this->resolveWabaId($inspection, $data['waba_id'] ?? null);
        $phones = $this->meta->phoneNumbers($wabaId, $accessToken);
        $phone = $this->resolvePhone($phones, $data['phone_number_id'] ?? null);

        // Meta documenta una suscripción por WABA para recibir eventos de todos sus números.
        $this->meta->subscribeWaba($wabaId, $accessToken);

        $expiresAt = null;
        $expires = (int) ($inspection['expires_at'] ?? 0);
        if ($expires > 0) {
            $expiresAt = CarbonImmutable::createFromTimestampUTC($expires);
        }

        return $this->channels->saveEmbeddedConnection([
            'access_token' => $accessToken,
            'display_phone_number' => $phone['display_phone_number'] ?? null,
            'phone_number_id' => (string) $phone['id'],
            'waba_id' => $wabaId,
            'meta_user_id' => $inspection['user_id'] ?? null,
            'meta_app_id' => $inspection['app_id'] ?? $this->meta->appId(),
            'access_token_expires_at' => $expiresAt,
            'verified_name' => $phone['verified_name'] ?? null,
            'quality_rating' => $phone['quality_rating'] ?? null,
            'pdf_template_name' => config('rhino.nutrition.whatsapp_pdf_template'),
            'images_template_name' => config('rhino.nutrition.whatsapp_images_template'),
            'template_language' => config('rhino.nutrition.whatsapp_template_language', 'es_MX'),
        ], $partnerId);
    }

    private function authorizedPartnerId(?string $requestedPartnerId): string
    {
        if (!$this->context->isSuperAdmin() && !$this->context->hasPermission('organization.whatsapp.manage')) {
            throw new RuntimeException('No tienes permiso para conectar WhatsApp de la organización.', 403);
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
            throw new RuntimeException('No puedes conectar el WhatsApp de otro socio.', 403);
        }
        return $own;
    }

    /** @param array<string,mixed> $inspection */
    private function validateAppAndScopes(array $inspection): void
    {
        if ((string) ($inspection['app_id'] ?? '') !== $this->meta->appId()) {
            throw new RuntimeException('La autorización pertenece a otra aplicación de Meta.', 403);
        }

        $scopes = array_map('strval', is_array($inspection['scopes'] ?? null) ? $inspection['scopes'] : []);
        $granular = is_array($inspection['granular_scopes'] ?? null) ? $inspection['granular_scopes'] : [];
        foreach ($granular as $scope) {
            if (is_array($scope) && isset($scope['scope'])) {
                $scopes[] = (string) $scope['scope'];
            }
        }
        $scopes = array_values(array_unique($scopes));
        if (!in_array('whatsapp_business_management', $scopes, true)) {
            throw new RuntimeException('Meta no otorgó el permiso whatsapp_business_management.', 403);
        }
        if (!in_array('whatsapp_business_messaging', $scopes, true)) {
            throw new RuntimeException('Meta no otorgó el permiso whatsapp_business_messaging necesario para enviar planes.', 403);
        }
    }

    /** @param array<string,mixed> $inspection */
    private function resolveWabaId(array $inspection, mixed $requested): string
    {
        $targets = [];
        foreach (is_array($inspection['granular_scopes'] ?? null) ? $inspection['granular_scopes'] : [] as $scope) {
            if (!is_array($scope) || ($scope['scope'] ?? null) !== 'whatsapp_business_management') {
                continue;
            }
            foreach (is_array($scope['target_ids'] ?? null) ? $scope['target_ids'] : [] as $id) {
                if (trim((string) $id) !== '') $targets[] = trim((string) $id);
            }
        }
        $targets = array_values(array_unique($targets));
        $requested = trim((string) ($requested ?? ''));

        if ($requested !== '') {
            if ($targets !== [] && !in_array($requested, $targets, true)) {
                throw new RuntimeException('La WABA indicada no fue autorizada por Meta para esta sesión.', 403);
            }
            return $requested;
        }
        if (count($targets) === 1) {
            return $targets[0];
        }
        throw new RuntimeException('Meta no permitió determinar una única cuenta de WhatsApp. Vuelve a ejecutar la conexión.', 409);
    }

    /** @param list<array<string,mixed>> $phones @return array<string,mixed> */
    private function resolvePhone(array $phones, mixed $requested): array
    {
        $requested = trim((string) ($requested ?? ''));
        if ($requested !== '') {
            foreach ($phones as $phone) {
                if ((string) ($phone['id'] ?? '') === $requested) {
                    return $phone;
                }
            }
            throw new RuntimeException('El Phone Number ID no pertenece a la WABA autorizada.', 403);
        }
        if (count($phones) === 1 && trim((string) ($phones[0]['id'] ?? '')) !== '') {
            return $phones[0];
        }
        throw new RuntimeException('Meta devolvió varios números. Repite la conexión y selecciona uno de forma explícita.', 409);
    }

    private function graphVersion(): string
    {
        $url = rtrim((string) config('services.meta.api_url', ''), '/');
        $tail = basename($url);
        return preg_match('/^v\d+(?:\.\d+)?$/', $tail) ? $tail : 'v25.0';
    }
}
