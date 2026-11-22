<?php

namespace App\Modules\Integrations\Whatsapp\Services;

use App\Modules\Campaigns\Services\CampaignWhatsappCaptureService;
use App\Modules\NutritionCampaigns\Services\NutritionCampaignStageService;
use App\Modules\PersonalReports\Services\ContactPublicCodeService;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class WhatsappInboundService
{
    public function __construct(
        private readonly ContactPublicCodeService $codes,
        private readonly NutritionCampaignStageService $campaignStages,
        private readonly CampaignWhatsappCaptureService $capture,
    ) {}

    /** @param array<string,mixed> $value @param array<string,mixed> $message */
    public function handle(array $value, array $message): void
    {
        $externalId = trim((string) ($message['id'] ?? ''));
        $from = $this->digits((string) ($message['from'] ?? ''));
        if ($externalId === '' || $from === '') {
            return;
        }

        $receivedAt = isset($message['timestamp']) && is_numeric($message['timestamp'])
            ? date('Y-m-d H:i:s', (int) $message['timestamp'])
            : now()->format('Y-m-d H:i:s');
        $messageType = strtoupper((string) ($message['type'] ?? 'TEXT'));
        $body = $this->body($message);
        $publicCode = $this->codes->extract($body);

        $campaignReply = null;

        DB::transaction(function () use ($value, $externalId, $from, $receivedAt, $messageType, $body, $publicCode, &$campaignReply): void {
            if (DB::table('whatsapp_inbound_messages')->where('id', $externalId)->exists()) {
                return;
            }

            $receiverPhoneNumberId = trim((string) ($value['metadata']['phone_number_id'] ?? ''));
            $channelPartnerId = null;
            if ($receiverPhoneNumberId !== '' && Schema::hasTable('partner_whatsapp_channels')) {
                $channel = DB::table('partner_whatsapp_channels')
                    ->where('phone_number_id', $receiverPhoneNumberId)
                    ->first();
                if ($channel !== null) {
                    $channelPartnerId = (string) $channel->partner_id;
                }
            }

            $contact = null;
            if ($publicCode !== null) {
                $contactQuery = DB::table('contacts')
                    ->where('public_code', $publicCode)
                    ->whereNull('deleted_at');
                if ($channelPartnerId !== null) {
                    $contactQuery->where('partner_id', $channelPartnerId);
                }
                $contact = $contactQuery->first();
            }

            $existingPhoneLink = null;
            if ($contact === null) {
                $linkQuery = DB::table('whatsapp_contact_links')
                    ->where('phone_number', $from)
                    ->where('status', 'ACTIVE');
                if ($channelPartnerId !== null) {
                    $linkQuery->where('partner_id', $channelPartnerId);
                }
                $existingPhoneLink = $linkQuery
                    ->orderByDesc('last_inbound_at')
                    ->first();
                if ($existingPhoneLink !== null) {
                    $contact = DB::table('contacts')
                        ->where('id', $existingPhoneLink->contact_id)
                        ->where('partner_id', $existingPhoneLink->partner_id)
                        ->whereNull('deleted_at')
                        ->first();
                }
            }
            $partnerId = $contact?->partner_id !== null ? (string) $contact->partner_id : $channelPartnerId;
            $contactId = $contact?->id !== null ? (string) $contact->id : null;
            $linkStatus = 'UNMATCHED';

            if ($this->isOptOut($body)) {
                $linksQuery = DB::table('whatsapp_contact_links')->where('phone_number', $from)->where('status', 'ACTIVE');
                if ($channelPartnerId !== null) {
                    $linksQuery->where('partner_id', $channelPartnerId);
                }
                $links = $linksQuery->get();
                foreach ($links as $link) {
                    DB::table('whatsapp_contact_links')->where('id', $link->id)->update([
                        'status' => 'REVOKED',
                        'report_consent_at' => null,
                        'marketing_consent_at' => null,
                        'revoked_at' => now(),
                        'last_inbound_at' => $receivedAt,
                        'updated_at' => now(),
                    ]);
                    DB::table('health_data_consents')
                        ->where('contact_id', $link->contact_id)
                        ->whereIn('purpose', ['REPORT_DELIVERY', 'WHATSAPP_MARKETING'])
                        ->update(['status' => 'REVOKED', 'revoked_at' => now(), 'updated_at' => now()]);
                }
                $linkStatus = 'REVOKED';
                if ($partnerId !== null && $contactId !== null) {
                    $campaignReply = [
                        'partner_id' => $partnerId,
                        'contact_id' => $contactId,
                        'body' => $body,
                        'received_at' => $receivedAt,
                        'external_id' => $externalId,
                        'opt_out' => true,
                    ];
                }
            } elseif ($contact !== null) {
                if ($publicCode !== null && (string) ($contact->whatsapp_status ?? 'VERIFIED') === 'PENDING') {
                    $claim = $this->capture->claimPendingByPublicCode($publicCode, $from, $receivedAt);
                    if ($claim['conflict']) {
                        $linkStatus = 'PHONE_CONFLICT';
                    } elseif ($claim['claimed']) {
                        $contact = DB::table('contacts')->where('id', $contact->id)->first();
                        $partnerId = (string) $contact->partner_id;
                        $contactId = (string) $contact->id;
                    }
                }

                $storedPhone = $this->digits((string) ($contact->whatsapp_normalized ?? $contact->whatsapp ?? ''));
                if ($linkStatus === 'PHONE_CONFLICT') {
                    // El número ya está vinculado a otro contacto de la misma organización.
                } elseif ($storedPhone !== $from) {
                    $linkStatus = 'PHONE_MISMATCH';
                } else {
                    $explicitReportConsent = $this->hasReportConsent($body);
                    $marketingConsent = $this->hasMarketingConsent($body);
                    $existingLink = DB::table('whatsapp_contact_links')
                        ->where('partner_id', $partnerId)
                        ->where('contact_id', $contactId)
                        ->first();

                    if ($publicCode !== null) {
                        DB::table('whatsapp_contact_links')->updateOrInsert(
                            ['partner_id' => $partnerId, 'contact_id' => $contactId],
                            [
                                'public_code' => $publicCode,
                                'phone_number' => $from,
                                'status' => 'ACTIVE',
                                'verified_at' => $existingLink?->verified_at ?? now(),
                                'last_inbound_at' => $receivedAt,
                                'report_consent_at' => $explicitReportConsent ? now() : $existingLink?->report_consent_at,
                                'marketing_consent_at' => $marketingConsent ? now() : $existingLink?->marketing_consent_at,
                                'revoked_at' => null,
                                'consent_text' => mb_substr($body, 0, 2000),
                                'created_at' => $existingLink?->created_at ?? now(),
                                'updated_at' => now(),
                            ],
                        );
                    } elseif ($existingLink !== null) {
                        DB::table('whatsapp_contact_links')
                            ->where('id', $existingLink->id)
                            ->update([
                                'last_inbound_at' => $receivedAt,
                                'updated_at' => now(),
                            ]);
                    }

                    if ($explicitReportConsent) {
                        $this->upsertConsent($partnerId, $contactId, 'REPORT_DELIVERY', $body, $externalId);
                        $linkStatus = 'LINKED_REPORT_CONSENT';
                    } elseif ($existingLink !== null) {
                        $linkStatus = 'LINKED_EXISTING';
                    } else {
                        $linkStatus = 'LINKED_NO_REPORT_CONSENT';
                    }
                    if ($marketingConsent) {
                        $this->upsertConsent($partnerId, $contactId, 'WHATSAPP_MARKETING', $body, $externalId);
                    }

                    DB::table('timeline_activities')->insertOrIgnore([
                        'id' => 'TML_WA_'.strtoupper(substr(hash('sha256', $externalId), 0, 24)),
                        'partner_id' => $partnerId,
                        'contact_id' => $contactId,
                        'type' => 'WHATSAPP_INBOUND',
                        'description' => $explicitReportConsent
                            ? 'La persona vinculó su código RHINO y autorizó recibir el informe por WhatsApp.'
                            : 'La persona envió un mensaje por WhatsApp.',
                        'metadata' => SqlValue::encode([
                            'external_id' => $externalId,
                            'public_code' => $publicCode,
                            'report_consent' => $explicitReportConsent,
                            'marketing_consent' => $marketingConsent,
                        ]),
                        'actor_id' => 'SYSTEM_WHATSAPP',
                        'created_at' => now(),
                    ]);

                    $campaignReply = [
                        'partner_id' => $partnerId,
                        'contact_id' => $contactId,
                        'body' => $body,
                        'received_at' => $receivedAt,
                        'external_id' => $externalId,
                        'opt_out' => false,
                    ];
                }
            }

            DB::table('whatsapp_inbound_messages')->insert([
                'id' => $externalId,
                'partner_id' => $partnerId,
                'contact_id' => $contactId,
                'from_phone_number' => $from,
                'message_type' => $messageType,
                'public_code' => $publicCode,
                'link_status' => $linkStatus,
                'body' => $body !== '' ? mb_substr($body, 0, 4000) : null,
                'metadata' => SqlValue::encode([
                    'phone_number_id' => $value['metadata']['phone_number_id'] ?? null,
                    'display_phone_number' => $value['metadata']['display_phone_number'] ?? null,
                ]),
                'received_at' => $receivedAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);

        if (is_array($campaignReply)) {
            $this->applyCampaignReply($campaignReply);
        }
    }

    private function upsertConsent(string $partnerId, string $contactId, string $purpose, string $body, string $externalId): void
    {
        DB::table('health_data_consents')->updateOrInsert(
            ['contact_id' => $contactId, 'purpose' => $purpose],
            [
                'partner_id' => $partnerId,
                'status' => 'GRANTED',
                'source' => 'WHATSAPP_INBOUND',
                'evidence_text' => mb_substr($body, 0, 2000),
                'evidence' => SqlValue::encode(['whatsapp_message_id' => $externalId]),
                'granted_at' => now(),
                'revoked_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /** @param array<string,mixed> $reply */
    private function applyCampaignReply(array $reply): void
    {
        if (!Schema::hasTable('nutrition_followup_members')
            || !Schema::hasTable('nutrition_followup_campaigns')) {
            return;
        }

        $member = DB::table('nutrition_followup_members as member')
            ->join(
                'nutrition_followup_campaigns as campaign',
                'campaign.id',
                '=',
                'member.campaign_id'
            )
            ->where('member.partner_id', $reply['partner_id'])
            ->where('member.contact_id', $reply['contact_id'])
            ->where('campaign.status', 'ACTIVE')
            ->whereNull('campaign.deleted_at')
            ->whereNotIn('member.stage', [
                'SALE_COMPLETED', 'OPTED_OUT', 'DECLINED', 'NO_SHOW',
            ])
            ->select('member.*')
            ->orderByDesc('member.created_at')
            ->first();

        if ($member === null) {
            return;
        }

        if ((bool) ($reply['opt_out'] ?? false)) {
            $this->campaignStages->transition(
                (string) $member->id,
                'OPTED_OUT',
                'WHATSAPP_REPLY',
                null,
                'La persona solicitó dejar de recibir mensajes.',
            );
            return;
        }

        $body = trim((string) ($reply['body'] ?? ''));
        if ($body === '' || $this->hasReportConsent($body)) {
            return;
        }

        $stage = 'RESPONDED';
        if (preg_match('/\b(S[IÍ]|CONFIRMO|RESERVAR|QUIERO\s+ASISTIR|ASISTIR[EÉ])\b/ui', $body) === 1) {
            $stage = 'CONFIRMED';
        } elseif (preg_match('/\b(NO|NO\s+PUEDO|NO\s+GRACIAS|DECLINO)\b/ui', $body) === 1) {
            $stage = 'DECLINED';
        }

        $this->campaignStages->transition(
            (string) $member->id,
            $stage,
            'WHATSAPP_REPLY',
            null,
            'Respuesta recibida por WhatsApp: '.mb_substr($body, 0, 500),
        );
    }

    /** @param array<string,mixed> $message */
    private function body(array $message): string
    {
        return trim((string) (
            $message['text']['body']
            ?? $message['button']['text']
            ?? $message['interactive']['button_reply']['title']
            ?? $message['interactive']['list_reply']['title']
            ?? ''
        ));
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function isOptOut(string $body): bool
    {
        return preg_match('/\b(BAJA|STOP|CANCELAR|NO\s+QUIERO)\b/ui', $body) === 1;
    }

    private function hasReportConsent(string $body): bool
    {
        return preg_match('/\bAUTORIZO\b/ui', $body) === 1
            && preg_match('/\b(INFORME|RESULTADO|PESAJE)\b/ui', $body) === 1;
    }

    private function hasMarketingConsent(string $body): bool
    {
        return preg_match('/\bAUTORIZO\b/ui', $body) === 1
            && preg_match('/\b(INVITACIONES|PROMOCIONES|CAMPAÑAS|EVENTOS)\b/ui', $body) === 1;
    }
}
