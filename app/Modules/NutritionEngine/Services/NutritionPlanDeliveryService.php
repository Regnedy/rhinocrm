<?php

namespace App\Modules\NutritionEngine\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Integrations\Whatsapp\Contracts\WhatsappChannelResolverInterface;
use App\Modules\Integrations\Whatsapp\Contracts\WhatsappProviderInterface;
use App\Modules\Movements\Services\MovementService;
use App\Support\Database\SqlValue;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NutritionPlanDeliveryService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly NutritionPlanPdfService $pdf,
        private readonly NutritionPlanImageService $images,
        private readonly WhatsappProviderInterface $whatsapp,
        private readonly WhatsappChannelResolverInterface $channels,
        private readonly MovementService $movements,
    ) {}

    /** @return array<string,mixed> */
    public function shareInfo(string $planId): array
    {
        $plan = $this->pdf->authorizedPlan($planId);
        $file = $this->pdf->generate($planId);
        $phone = $this->digits((string) $plan->whatsapp_normalized);
        $message = $this->message($plan);
        $link = DB::table('whatsapp_contact_links')
            ->where('partner_id', $plan->partner_id)
            ->where('contact_id', $plan->contact_id)
            ->first();
        $windowHours = max(1, (int) config('rhino.personal_reports.whatsapp_window_hours', 24));
        $windowOpen = $link !== null
            && $link->last_inbound_at !== null
            && Carbon::parse((string) $link->last_inbound_at)->gte(now()->subHours($windowHours));
        $channel = $this->channels->summary((string) $plan->partner_id);
        $template = trim((string) ($channel['pdf_template_name'] ?? ''));
        $metaConfigured = (bool) ($channel['configured'] ?? false) && (bool) ($channel['active'] ?? false);
        $verified = $phone !== ''
            && $link !== null
            && (string) $link->status === 'ACTIVE'
            && $link->report_consent_at !== null
            && $this->digits((string) $link->phone_number) === $phone;

        return [
            'plan_id' => (string) $plan->id,
            'contact_id' => (string) $plan->contact_id,
            'contact_name' => (string) $plan->contact_name,
            'phone' => $phone !== '' ? $phone : null,
            'phone_e164' => $phone !== '' ? '+'.$phone : null,
            'filename' => $file['filename'],
            'pdf_url' => '/api/v1/nutrition-engine/plans/'.rawurlencode((string) $plan->id).'/pdf?download=1',
            'images_manifest_url' => '/api/v1/nutrition-engine/plans/'.rawurlencode((string) $plan->id).'/images?format=jpg',
            'message' => $message,
            'whatsapp_url' => $phone !== '' ? 'https://wa.me/'.$phone.'?text='.rawurlencode($message) : null,
            'native_share_supported' => true,
            'direct_send' => [
                'available' => $verified && $metaConfigured && ($windowOpen || $template !== ''),
                'verified_contact' => $verified,
                'conversation_window_open' => $windowOpen,
                'template_configured' => $template !== '',
                'meta_configured' => $metaConfigured,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function recordShareStarted(string $planId, string $channel = 'WHATSAPP_APP'): array
    {
        $plan = $this->pdf->authorizedPlan($planId);
        $channel = strtoupper(trim($channel));
        if (!in_array($channel, ['WHATSAPP_APP', 'ANDROID_SHARE', 'WEB_SHARE', 'DOWNLOAD'], true)) {
            $channel = 'WHATSAPP_APP';
        }

        DB::transaction(function () use ($plan, $channel): void {
            DB::table('nutrition_plan_versions')->where('id', $plan->id)->increment('share_count', 1, [
                'last_shared_at' => now(),
                'last_shared_by_user_id' => $this->context->userId,
                'updated_at' => now(),
            ]);

            DB::table('timeline_activities')->insert([
                'id' => 'ACT_'.strtoupper((string) Str::ulid()),
                'partner_id' => (string) $plan->partner_id,
                'contact_id' => (string) $plan->contact_id,
                'type' => 'NUTRITION_PLAN_SHARE_STARTED',
                'description' => 'Se compartió el PDF del plan alimentario desde RHINO CRM.',
                'metadata' => SqlValue::encode([
                    'plan_id' => (string) $plan->id,
                    'channel' => $channel,
                ]),
                'actor_id' => $this->context->userId,
                'created_at' => now(),
            ]);
        }, 3);

        $this->movements->record(
            '605',
            (string) $plan->partner_id,
            'NUTRITION_PLAN',
            (string) $plan->id,
            ['contact_id' => (string) $plan->contact_id, 'channel' => $channel],
        );

        return $this->shareInfo($planId);
    }

    /** @return array<string,mixed> */
    public function sendDirect(
        string $planId,
        string $format = 'pdf',
        ?string $phone = null,
        bool $captureKioskConsent = false,
        string $consentText = '',
    ): array {
        $plan = $this->pdf->authorizedPlan($planId);
        $format = strtolower(trim($format));
        if (!in_array($format, ['pdf', 'images', 'both'], true)) {
            throw new \RuntimeException('Formato de entrega no soportado.', 422);
        }

        $targetPhone = $this->digits((string) ($phone ?? $plan->whatsapp_normalized));
        $storedPhone = $this->digits((string) $plan->whatsapp_normalized);
        if ($targetPhone === '') {
            throw new \RuntimeException('El prospecto no tiene un WhatsApp válido.', 409);
        }
        if ($storedPhone !== '' && $storedPhone !== $targetPhone) {
            throw new \RuntimeException('El WhatsApp solicitado no coincide con el prospecto.', 409);
        }

        if ($captureKioskConsent) {
            $this->captureKioskConsent($plan, $targetPhone, $consentText);
        }

        $link = DB::table('whatsapp_contact_links')
            ->where('partner_id', $plan->partner_id)
            ->where('contact_id', $plan->contact_id)
            ->first();
        if ($link === null || (string) $link->status !== 'ACTIVE' || $link->report_consent_at === null) {
            throw new \RuntimeException('Se requiere autorización para enviar el plan por WhatsApp.', 409);
        }
        if ($this->digits((string) $link->phone_number) !== $targetPhone) {
            throw new \RuntimeException('El WhatsApp autorizado no coincide con el expediente.', 409);
        }

        $windowHours = max(1, (int) config('rhino.personal_reports.whatsapp_window_hours', 24));
        $windowOpen = $link->last_inbound_at !== null
            && Carbon::parse((string) $link->last_inbound_at)->gte(now()->subHours($windowHours));

        $result = match ($format) {
            'pdf' => $this->sendPdfDirect($plan, $targetPhone, $windowOpen),
            'images' => $this->sendImagesDirect($plan, $targetPhone, $windowOpen),
            'both' => $this->sendBothDirect($plan, $targetPhone, $windowOpen),
        };

        $externalIds = $result['external_ids'];
        $mediaIds = $result['media_ids'];
        $messageTypes = $result['message_types'];
        $templateNames = $result['template_names'];
        $pages = $result['pages'];
        $primaryExternalId = (string) ($externalIds[0] ?? '');

        DB::transaction(function () use ($plan, $targetPhone, $format, $externalIds, $mediaIds, $messageTypes, $templateNames, $pages, $primaryExternalId, $windowOpen): void {
            DB::table('nutrition_plan_versions')->where('id', $plan->id)->update([
                'delivery_status' => 'SENT',
                'whatsapp_message_id' => $primaryExternalId !== '' ? $primaryExternalId : null,
                'last_shared_at' => now(),
                'last_shared_by_user_id' => $this->context->userId,
                'share_count' => DB::raw('share_count + 1'),
                'updated_at' => now(),
            ]);

            foreach ($externalIds as $index => $externalId) {
                $messageId = 'WAM_NPL_'.strtoupper(substr(hash('sha256', $externalId.'|'.$plan->id.'|'.$index), 0, 28));
                DB::table('whatsapp_messages')->updateOrInsert(
                    ['id' => $messageId],
                    [
                        'partner_id' => (string) $plan->partner_id,
                        'contact_id' => (string) $plan->contact_id,
                        'direction' => 'OUTBOUND',
                        'message_type' => $messageTypes[$index] ?? 'DOCUMENT',
                        'to_phone_number' => $targetPhone,
                        'template_name' => $windowOpen ? '__DIRECT_QUICK_NUTRITION__' : ($templateNames[$index] ?? ''),
                        'variables' => SqlValue::encode([
                            'nutrition_plan_id' => (string) $plan->id,
                            'measurement_id' => (string) $plan->measurement_id,
                            'format' => strtoupper($format),
                            'item_type' => $messageTypes[$index] ?? null,
                            'page' => $pages[$index] ?? null,
                        ]),
                        'status' => 'SENT',
                        'external_id' => $externalId,
                        'media_id' => $mediaIds[$index] ?? null,
                        'error_message' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }

            DB::table('timeline_activities')->insert([
                'id' => 'ACT_'.strtoupper((string) Str::ulid()),
                'partner_id' => (string) $plan->partner_id,
                'contact_id' => (string) $plan->contact_id,
                'type' => 'NUTRITION_PLAN_SENT',
                'description' => match ($format) {
                    'pdf' => 'Plan alimentario PDF enviado directamente por WhatsApp Cloud API.',
                    'images' => 'Imágenes del plan alimentario enviadas directamente por WhatsApp Cloud API.',
                    'both' => 'Plan alimentario PDF e imágenes enviados directamente por WhatsApp Cloud API.',
                },
                'metadata' => SqlValue::encode([
                    'plan_id' => (string) $plan->id,
                    'format' => strtoupper($format),
                    'whatsapp_message_ids' => $externalIds,
                ]),
                'actor_id' => $this->context->userId,
                'created_at' => now(),
            ]);
        }, 3);

        $movement = $this->movements->record(
            '606',
            (string) $plan->partner_id,
            'NUTRITION_PLAN',
            (string) $plan->id,
            [
                'contact_id' => (string) $plan->contact_id,
                'format' => strtoupper($format),
                'external_ids' => $externalIds,
                'sent_count' => count($externalIds),
            ],
        );

        return [
            'plan_id' => (string) $plan->id,
            'status' => 'SENT',
            'format' => match ($format) { 'images' => 'IMAGES', 'both' => 'BOTH', default => 'PDF' },
            'phone' => $targetPhone,
            'external_ids' => $externalIds,
            'sent_count' => count($externalIds),
            'conversation_window_open' => $windowOpen,
            'used_template' => !$windowOpen,
            'movement' => $movement,
        ];
    }

    /** @return array{external_ids:list<string>,media_ids:list<string>,message_types:list<string>,template_names:list<string>,pages:list<?int>} */
    private function sendBothDirect(object $plan, string $phone, bool $windowOpen): array
    {
        if (!$windowOpen) {
            $channel = $this->channels->resolve((string) $plan->partner_id);
            $pdfTemplate = trim((string) ($channel->pdfTemplateName ?? ''));
            $imageTemplate = trim((string) ($channel->imagesTemplateName ?? ''));
            if ($pdfTemplate === '' || $imageTemplate === '') {
                throw new \RuntimeException('Para ENVIAR AMBAS configura las plantillas DOCUMENT e IMAGE en Organización > WhatsApp del socio antes de iniciar la entrega.', 409);
            }
        }

        $pdf = $this->sendPdfDirect($plan, $phone, $windowOpen);
        $images = $this->sendImagesDirect($plan, $phone, $windowOpen);

        return [
            'external_ids' => array_merge($pdf['external_ids'], $images['external_ids']),
            'media_ids' => array_merge($pdf['media_ids'], $images['media_ids']),
            'message_types' => array_merge($pdf['message_types'], $images['message_types']),
            'template_names' => array_merge($pdf['template_names'], $images['template_names']),
            'pages' => array_merge($pdf['pages'], $images['pages']),
        ];
    }

    /** @return array{external_ids:list<string>,media_ids:list<string>,message_types:list<string>,template_names:list<string>,pages:list<?int>} */
    private function sendPdfDirect(object $plan, string $phone, bool $windowOpen): array
    {
        $channel = $this->channels->resolve((string) $plan->partner_id);
        $file = $this->pdf->generate((string) $plan->id);
        $mediaId = $this->whatsapp->uploadDocument($file['absolute_path'], $file['filename'], 'application/pdf', (string) $plan->partner_id);
        $template = trim((string) ($channel->pdfTemplateName ?? ''));
        if (!$windowOpen && $template === '') {
            throw new \RuntimeException('Configura una plantilla de WhatsApp con encabezado DOCUMENT para enviar el PDF sin conversación previa.', 409);
        }
        $externalId = $windowOpen
            ? $this->whatsapp->sendDocument($phone, $mediaId, $file['filename'], $this->message($plan), (string) $plan->partner_id)
            : $this->whatsapp->sendDocumentTemplate(
                $phone,
                $template,
                $mediaId,
                $file['filename'],
                ['1' => (string) $plan->contact_name, '2' => (string) ($plan->public_code ?? '')],
                $channel->templateLanguage,
                (string) $plan->partner_id,
            );

        return ['external_ids' => [$externalId], 'media_ids' => [$mediaId], 'message_types' => ['DOCUMENT'], 'template_names' => [$template], 'pages' => [null]];
    }

    /** @return array{external_ids:list<string>,media_ids:list<string>,message_types:list<string>,template_names:list<string>,pages:list<?int>} */
    private function sendImagesDirect(object $plan, string $phone, bool $windowOpen): array
    {
        $manifest = $this->images->generate((string) $plan->id, 'jpg');
        $pages = is_array($manifest['pages'] ?? null) ? $manifest['pages'] : [];
        if ($pages === []) {
            throw new \RuntimeException('No se generaron imágenes para el plan.', 503);
        }
        $channel = $this->channels->resolve((string) $plan->partner_id);
        $template = trim((string) ($channel->imagesTemplateName ?? ''));
        if (!$windowOpen && $template === '') {
            throw new \RuntimeException('Configura una plantilla de WhatsApp con encabezado IMAGE para enviar imágenes sin conversación previa.', 409);
        }

        $externalIds = [];
        $mediaIds = [];
        $total = count($pages);
        foreach ($pages as $index => $page) {
            $mediaId = $this->whatsapp->uploadImage(
                (string) $page['absolute_path'],
                (string) $page['filename'],
                (string) ($page['content_type'] ?? 'image/jpeg'),
                (string) $plan->partner_id,
            );
            $mediaIds[] = $mediaId;
            $externalIds[] = $windowOpen
                ? $this->whatsapp->sendImage(
                    $phone,
                    $mediaId,
                    $index === 0 ? $this->message($plan) : '',
                    (string) $plan->partner_id,
                )
                : $this->whatsapp->sendImageTemplate(
                    $phone,
                    $template,
                    $mediaId,
                    [
                        '1' => (string) $plan->contact_name,
                        '2' => (string) ($index + 1),
                        '3' => (string) $total,
                    ],
                    $channel->templateLanguage,
                    (string) $plan->partner_id,
                );
        }

        return [
            'external_ids' => $externalIds,
            'media_ids' => $mediaIds,
            'message_types' => array_fill(0, count($externalIds), 'IMAGE'),
            'template_names' => array_fill(0, count($externalIds), $template),
            'pages' => $externalIds === [] ? [] : range(1, count($externalIds)),
        ];
    }

    private function captureKioskConsent(object $plan, string $phone, string $consentText): void
    {
        $conflict = DB::table('whatsapp_contact_links')
            ->where('partner_id', (string) $plan->partner_id)
            ->where('phone_number', $phone)
            ->where('contact_id', '<>', (string) $plan->contact_id)
            ->where('status', 'ACTIVE')
            ->exists();
        if ($conflict) {
            throw new \RuntimeException('Ese WhatsApp ya está vinculado a otro prospecto de la organización.', 409);
        }

        $text = trim($consentText) !== ''
            ? trim($consentText)
            : 'La persona autorizó en RHINO Kiosco recibir su Plan Alimenticio Rápido por WhatsApp.';
        $existing = DB::table('whatsapp_contact_links')
            ->where('partner_id', (string) $plan->partner_id)
            ->where('contact_id', (string) $plan->contact_id)
            ->first();

        DB::transaction(function () use ($plan, $phone, $text, $existing): void {
            DB::table('whatsapp_contact_links')->updateOrInsert(
                ['partner_id' => (string) $plan->partner_id, 'contact_id' => (string) $plan->contact_id],
                [
                    'public_code' => (string) ($plan->public_code ?? ''),
                    'phone_number' => $phone,
                    'status' => 'ACTIVE',
                    'verified_at' => $existing?->verified_at,
                    'last_inbound_at' => $existing?->last_inbound_at,
                    'report_consent_at' => now(),
                    'marketing_consent_at' => $existing?->marketing_consent_at,
                    'revoked_at' => null,
                    'consent_text' => mb_substr($text, 0, 2000),
                    'created_at' => $existing?->created_at ?? now(),
                    'updated_at' => now(),
                ],
            );

            DB::table('health_data_consents')->updateOrInsert(
                ['contact_id' => (string) $plan->contact_id, 'purpose' => 'REPORT_DELIVERY'],
                [
                    'partner_id' => (string) $plan->partner_id,
                    'status' => 'GRANTED',
                    'source' => 'ANDROID_KIOSK',
                    'evidence_text' => mb_substr($text, 0, 2000),
                    'evidence' => SqlValue::encode([
                        'nutrition_plan_id' => (string) $plan->id,
                        'measurement_id' => (string) $plan->measurement_id,
                        'phone_number' => $phone,
                    ]),
                    'granted_at' => now(),
                    'revoked_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }, 3);
    }

    private function message(object $plan): string
    {
        $date = $plan->generated_at !== null
            ? Carbon::parse((string) $plan->generated_at)->locale('es')->translatedFormat('d \d\e F \d\e Y')
            : now()->locale('es')->translatedFormat('d \d\e F \d\e Y');

        return "Hola {$plan->contact_name} 👋\n\n"
            ."Te compartimos tu plan alimenticio RHINO, preparado con base en tu pesaje y en los datos registrados en tu perfil.\n\n"
            ."📄 Plan: {$plan->id}\n"
            ."⚖️ Fecha: {$date}\n"
            ."🥗 Incluye menú, recetas, sustituciones y recomendaciones.\n\n"
            ."Este material es una orientación general y no sustituye una valoración médica o nutricional profesional.";
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
