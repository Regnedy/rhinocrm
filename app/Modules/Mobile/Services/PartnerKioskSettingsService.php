<?php

namespace App\Modules\Mobile\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PartnerKioskSettingsService
{
    public const DEFAULT_WAITING_TEMPLATE = "Hola {nombre} 👋\n\nTu evaluación RHINO quedó registrada correctamente.\n\n🥗 Estamos preparando tu Plan Alimenticio Personalizado utilizando los datos obtenidos durante tu pesaje.\n\nEstará listo en un plazo máximo de {plazo} y lo recibirás por este WhatsApp en formato PDF e imágenes.\n\n📲 Guarda este número para identificar la entrega de tu plan y recibir tu seguimiento.\n\nGracias por realizar tu evaluación con {socio}.";
    public const DEFAULT_WAITING_DEADLINE = '24 horas';
    private const ALLOWED_VARIABLES = ['{nombre}', '{socio}', '{plazo}'];

    public function __construct(private readonly UserContextDTO $context) {}

    /** @return array<string,mixed> */
    public function show(?string $requestedPartnerId = null): array
    {
        $partnerId = $this->authorizedPartnerId($requestedPartnerId, false);
        $row = DB::table('partner_kiosk_settings')->where('partner_id', $partnerId)->first();

        return [
            'partner_id' => $partnerId,
            'whatsapp_waiting_template' => $row?->whatsapp_waiting_template ?? self::DEFAULT_WAITING_TEMPLATE,
            'whatsapp_waiting_deadline' => $row?->whatsapp_waiting_deadline ?? self::DEFAULT_WAITING_DEADLINE,
            'version' => (int) ($row?->whatsapp_waiting_template_version ?? 1),
            'is_default' => $row === null || (
                (string) $row->whatsapp_waiting_template === self::DEFAULT_WAITING_TEMPLATE
                && (string) $row->whatsapp_waiting_deadline === self::DEFAULT_WAITING_DEADLINE
            ),
            'updated_at' => $row?->updated_at,
        ];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function save(array $data, ?string $requestedPartnerId = null): array
    {
        $partnerId = $this->authorizedPartnerId($requestedPartnerId, true);
        $template = trim((string) ($data['whatsapp_waiting_template'] ?? ''));
        $deadline = trim((string) ($data['whatsapp_waiting_deadline'] ?? ''));
        if ($template === '') {
            throw new RuntimeException('El mensaje de espera no puede quedar vacío.', 422);
        }
        if (mb_strlen($template) > 2000) {
            throw new RuntimeException('El mensaje de espera no puede superar 2000 caracteres.', 422);
        }
        if ($deadline === '' || mb_strlen($deadline) > 60) {
            throw new RuntimeException('El plazo debe contener entre 1 y 60 caracteres.', 422);
        }
        $this->validateTemplateVariables($template);

        $current = DB::table('partner_kiosk_settings')->where('partner_id', $partnerId)->first();
        $version = max(1, (int) ($current?->whatsapp_waiting_template_version ?? 0) + 1);
        $values = [
            'whatsapp_waiting_template' => $template,
            'whatsapp_waiting_deadline' => $deadline,
            'whatsapp_waiting_template_version' => $version,
            'updated_by_user_id' => $this->context->userId,
            'updated_at' => now(),
        ];
        if ($current === null) {
            DB::table('partner_kiosk_settings')->insert(array_merge($values, [
                'partner_id' => $partnerId,
                'created_at' => now(),
            ]));
        } else {
            DB::table('partner_kiosk_settings')->where('partner_id', $partnerId)->update($values);
        }

        return $this->show($partnerId);
    }

    /** @return array<string,mixed> */
    public function reset(?string $requestedPartnerId = null): array
    {
        return $this->save([
            'whatsapp_waiting_template' => self::DEFAULT_WAITING_TEMPLATE,
            'whatsapp_waiting_deadline' => self::DEFAULT_WAITING_DEADLINE,
        ], $requestedPartnerId);
    }

    public function validateTemplateVariables(string $template): void
    {
        preg_match_all('/\{[^{}]+\}/u', $template, $matches);
        $unknown = array_values(array_diff(array_unique($matches[0] ?? []), self::ALLOWED_VARIABLES));
        if ($unknown !== []) {
            throw new RuntimeException('Variables no permitidas: '.implode(', ', $unknown).'. Usa solo {nombre}, {socio} y {plazo}.', 422);
        }
    }

    private function authorizedPartnerId(?string $requestedPartnerId, bool $manage): string
    {
        if ($manage && !$this->context->isSuperAdmin() && !$this->context->hasPermission('organization.kiosk.manage')) {
            throw new RuntimeException('No tienes permiso para administrar la configuración de Kiosco.', 403);
        }
        if (!$manage && !$this->context->isSuperAdmin()
            && !$this->context->hasPermission('organization.read')
            && !$this->context->hasPermission('mobile.context')) {
            throw new RuntimeException('No tienes permiso para consultar la configuración de Kiosco.', 403);
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
            throw new RuntimeException('No puedes administrar la configuración de Kiosco de otro socio.', 403);
        }
        return $own;
    }
}
