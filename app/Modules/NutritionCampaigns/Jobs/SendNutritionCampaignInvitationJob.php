<?php

namespace App\Modules\NutritionCampaigns\Jobs;

use App\Modules\Integrations\Whatsapp\Contracts\WhatsappProviderInterface;
use App\Modules\NutritionCampaigns\Services\NutritionCampaignStageService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class SendNutritionCampaignInvitationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly string $messageId) {}

    public function handle(
        WhatsappProviderInterface $whatsapp,
        NutritionCampaignStageService $stages,
    ): void {
        $message = DB::table('nutrition_followup_messages')
            ->where('id', $this->messageId)
            ->first();
        if ($message === null || in_array((string) $message->status, [
            'SENT', 'DELIVERED', 'READ', 'SKIPPED',
        ], true)) {
            return;
        }

        DB::table('nutrition_followup_messages')->where('id', $this->messageId)->update([
            'status' => 'PROCESSING',
            'processing_at' => now(),
            'last_error' => null,
            'updated_at' => now(),
        ]);

        try {
            $row = DB::table('nutrition_followup_messages as msg')
                ->join('nutrition_followup_members as member', 'member.id', '=', 'msg.member_id')
                ->join('nutrition_followup_campaigns as campaign', 'campaign.id', '=', 'msg.campaign_id')
                ->join('contacts as contact', 'contact.id', '=', 'msg.contact_id')
                ->leftJoin('campaigns as event', 'event.id', '=', 'campaign.linked_event_id')
                ->leftJoin('whatsapp_contact_links as link', function ($join): void {
                    $join->on('link.contact_id', '=', 'msg.contact_id')
                        ->on('link.partner_id', '=', 'msg.partner_id');
                })
                ->where('msg.id', $this->messageId)
                ->select(
                    'msg.*',
                    'member.stage as member_stage',
                    'member.plan_recommended_at',
                    'campaign.name as campaign_name',
                    'campaign.status as campaign_status',
                    'campaign.whatsapp_template_name as campaign_template_name',
                    'contact.name as contact_name',
                    'contact.whatsapp_normalized',
                    'contact.public_code',
                    'event.name as event_name',
                    'event.starts_at as event_starts_at',
                    'event.venue_name as event_venue_name',
                    'link.phone_number',
                    'link.status as link_status',
                    'link.last_inbound_at',
                    'link.marketing_consent_at',
                )
                ->first();

            if ($row === null) {
                throw new \RuntimeException('No fue posible cargar la invitación.');
            }
            if (in_array((string) $row->campaign_status, ['CLOSED', 'CANCELLED'], true)) {
                $this->skip('CAMPAIGN_CLOSED', 'La campaña fue cerrada antes del envío.');
                return;
            }
            if ((string) ($row->link_status ?? '') !== 'ACTIVE'
                || $row->marketing_consent_at === null) {
                $this->skip('NO_MARKETING_CONSENT', 'La persona no autorizó recibir invitaciones.');
                $stages->transition(
                    (string) $row->member_id,
                    'OPTED_OUT',
                    'WHATSAPP',
                    null,
                    'La autorización de WhatsApp ya no está activa.',
                );
                return;
            }

            $recipient = $this->digits((string) ($row->phone_number ?? ''));
            $stored = $this->digits((string) ($row->whatsapp_normalized ?? ''));
            if ($recipient === '' || $recipient !== $stored) {
                $this->skip('PHONE_MISMATCH', 'El número vinculado no coincide con el expediente.');
                return;
            }

            $body = $this->body($row);
            $template = trim((string) (
                $row->campaign_template_name
                ?: config('rhino.nutrition_campaigns.whatsapp_template')
            ));
            $windowHours = max(
                1,
                (int) config('rhino.nutrition_campaigns.whatsapp_window_hours', 24)
            );
            $insideWindow = $row->last_inbound_at !== null
                && Carbon::parse((string) $row->last_inbound_at)
                    ->gte(now()->subHours($windowHours));

            if ($insideWindow) {
                $externalId = $whatsapp->sendText($recipient, $body, (string) $row->partner_id);
                $templateUsed = null;
            } elseif ($template !== '') {
                $externalId = $whatsapp->sendTemplate(
                    $recipient,
                    $template,
                    $this->templateVariables($row),
                    (string) config(
                        'rhino.nutrition_campaigns.whatsapp_template_language',
                        'es_MX'
                    ),
                    (string) $row->partner_id,
                );
                $templateUsed = $template;
            } else {
                $this->skip(
                    'WINDOW_EXPIRED_NO_TEMPLATE',
                    'La conversación expiró y no hay una plantilla aprobada configurada.'
                );
                return;
            }

            DB::transaction(function () use (
                $row,
                $recipient,
                $body,
                $externalId,
                $templateUsed,
                $stages,
            ): void {
                DB::table('nutrition_followup_messages')
                    ->where('id', $this->messageId)
                    ->update([
                        'recipient' => $recipient,
                        'status' => 'SENT',
                        'external_id' => $externalId,
                        'template_name' => $templateUsed,
                        'rendered_body' => $body,
                        'sent_at' => now(),
                        'last_error' => null,
                        'updated_at' => now(),
                    ]);

                $stages->transition(
                    (string) $row->member_id,
                    'INVITATION_SENT',
                    'WHATSAPP',
                    null,
                    'Invitación enviada individualmente por WhatsApp.',
                    ['invitation_status' => 'SENT'],
                );

                DB::table('whatsapp_messages')->insertOrIgnore([
                    'id' => 'WAM_'.strtoupper(substr(hash(
                        'sha256',
                        $this->messageId.'|'.$externalId
                    ), 0, 28)),
                    'partner_id' => $row->partner_id,
                    'contact_id' => $row->contact_id,
                    'direction' => 'OUTBOUND',
                    'message_type' => $templateUsed === null ? 'TEXT' : 'TEMPLATE',
                    'to_phone_number' => $recipient,
                    'template_name' => $templateUsed ?? 'nutrition_followup_text',
                    'variables' => json_encode(
                        $this->templateVariables($row),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                    'status' => 'SENT',
                    'external_id' => $externalId,
                    'media_id' => null,
                    'error_message' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }, 3);
        } catch (\Throwable $exception) {
            DB::table('nutrition_followup_messages')
                ->where('id', $this->messageId)
                ->update([
                    'status' => 'FAILED',
                    'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                    'failed_at' => now(),
                    'updated_at' => now(),
                ]);
            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        DB::table('nutrition_followup_messages')
            ->where('id', $this->messageId)
            ->whereNotIn('status', ['SENT', 'DELIVERED', 'READ', 'SKIPPED'])
            ->update([
                'status' => 'FAILED',
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                'failed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function skip(string $reason, string $message): void
    {
        DB::table('nutrition_followup_messages')
            ->where('id', $this->messageId)
            ->update([
                'status' => 'SKIPPED',
                'skip_reason' => $reason,
                'last_error' => $message,
                'updated_at' => now(),
            ]);
    }

    private function body(object $row): string
    {
        $firstName = trim(explode(' ', trim((string) $row->contact_name))[0] ?? '');
        $firstName = $firstName !== '' ? $firstName : 'Hola';
        $planDate = $row->plan_recommended_at !== null
            ? Carbon::parse((string) $row->plan_recommended_at)->format('d/m/Y')
            : 'una fecha anterior';
        $eventName = trim((string) ($row->event_name ?? 'degustación y actualización RHINO'));
        $eventDate = $row->event_starts_at !== null
            ? Carbon::parse((string) $row->event_starts_at)->format('d/m/Y H:i')
            : 'por confirmar';
        $venue = trim((string) ($row->event_venue_name ?? ''));

        $lines = [
            'Hola '.$firstName.'.',
            '',
            'El '.$planDate.' recibiste una alimentación recomendada RHINO después de tu evaluación.',
            '',
            'Te invitamos a '.$eventName.' para conocer opciones de producto y actualizar tu pesaje.',
            'Fecha: '.$eventDate.'.',
        ];
        if ($venue !== '') {
            $lines[] = 'Lugar: '.$venue.'.';
        }
        $lines[] = '';
        $lines[] = 'Responde SÍ para confirmar tu interés o NO si prefieres no participar.';
        if (trim((string) ($row->public_code ?? '')) !== '') {
            $lines[] = 'Código RHINO: '.strtoupper((string) $row->public_code).'.';
        }

        return implode("\n", $lines);
    }

    /** @return array<int|string,string> */
    private function templateVariables(object $row): array
    {
        $firstName = trim(explode(' ', trim((string) $row->contact_name))[0] ?? '');
        return [
            1 => $firstName !== '' ? $firstName : 'Participante RHINO',
            2 => $row->plan_recommended_at !== null
                ? Carbon::parse((string) $row->plan_recommended_at)->format('d/m/Y')
                : '—',
            3 => trim((string) ($row->event_name ?? 'Degustación RHINO')),
            4 => $row->event_starts_at !== null
                ? Carbon::parse((string) $row->event_starts_at)->format('d/m/Y H:i')
                : 'Por confirmar',
            5 => strtoupper((string) ($row->public_code ?? '')),
        ];
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
