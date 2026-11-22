<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\CRM\Domain\ValueObjects\NormalizedPhoneNumber;
use App\Modules\PersonalReports\Services\ContactPublicCodeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CampaignWhatsappCaptureService
{
    public function __construct(private readonly ContactPublicCodeService $codes) {}

    /** @return array<string,mixed> */
    public function ensure(string $campaignId, string $contactId, bool $markShown = false): array
    {
        $row = $this->participant($campaignId, $contactId);
        $token = trim((string) ($row->whatsapp_capture_token ?? ''));
        $verified = ((string) ($row->whatsapp_status ?? 'PENDING')) === 'VERIFIED';
        $expiresAt = $this->date($row->whatsapp_capture_expires_at ?? null);
        $expired = !$verified && $expiresAt !== null && $expiresAt->isPast();
        if ($token === '' || $expired) {
            do {
                $token = Str::random(64);
            } while (DB::table('campaign_contacts')->where('whatsapp_capture_token', $token)->exists());

            $expiresAt = CarbonImmutable::now()->addHours(24);
            DB::table('campaign_contacts')
                ->where('campaign_id', $campaignId)
                ->where('contact_id', $contactId)
                ->update([
                    'whatsapp_capture_token' => $token,
                    'whatsapp_capture_status' => $verified ? 'CONFIRMED' : 'PENDING',
                    'whatsapp_capture_expires_at' => $expiresAt,
                    'whatsapp_qr_shown_at' => null,
                    'whatsapp_vcard_downloaded_at' => null,
                    'whatsapp_opened_at' => null,
                    'updated_at' => now(),
                ]);
            $row->whatsapp_capture_token = $token;
            $row->whatsapp_capture_expires_at = $expiresAt;
        }

        if ($markShown) {
            DB::table('campaign_contacts')
                ->where('campaign_id', $campaignId)
                ->where('contact_id', $contactId)
                ->update(['whatsapp_qr_shown_at' => now(), 'updated_at' => now()]);
        }

        $publicCode = $this->codes->ensure($contactId);
        $status = $verified ? 'CONFIRMED' : (string) ($row->whatsapp_capture_status ?? 'PENDING');
        $businessPhone = $this->businessPhone();

        return [
            'status' => $status,
            'verified' => $status === 'CONFIRMED',
            'public_code' => $publicCode,
            'url' => route('whatsapp-connect.show', ['token' => $token]),
            'countdown_seconds' => 300,
            'business_name' => (string) config('rhino.personal_reports.whatsapp_contact_name', 'RHINO CRM'),
            'business_phone' => $businessPhone,
            'configured' => $businessPhone !== '',
            'expires_at' => $row->whatsapp_capture_expires_at ?? null,
            'qr_shown_at' => $row->whatsapp_qr_shown_at ?? null,
            'vcard_downloaded_at' => $row->whatsapp_vcard_downloaded_at ?? null,
            'whatsapp_opened_at' => $row->whatsapp_opened_at ?? null,
            'confirmed_at' => $row->whatsapp_confirmed_at ?? $row->whatsapp_verified_at ?? null,
        ];
    }


    /** @return array<string,mixed> */
    public function confirmManually(string $campaignId, string $contactId, string $rawPhone, string $actorId): array
    {
        $row = $this->participant($campaignId, $contactId);
        if (in_array((string) ($row->campaign_status ?? ''), ['CLOSED', 'CANCELLED'], true)) {
            throw new \RuntimeException('El evento ya no admite cambios de WhatsApp.', 409);
        }
        $partner = DB::table('partners')->where('id', (string) $row->contact_partner_id)->first();
        if ($partner === null) throw new \RuntimeException('No fue posible resolver la organización del prospecto.', 422);
        $phone = (new NormalizedPhoneNumber($rawPhone, (string) ($partner->country_dial_code ?? '52')))->getValue();

        DB::transaction(function () use ($row, $phone, $campaignId, $contactId, $actorId): void {
            $contact = DB::table('contacts')->where('id', $contactId)->lockForUpdate()->first();
            if ($contact === null) throw new \RuntimeException('Prospecto no encontrado.', 404);
            $conflict = DB::table('contacts')
                ->where('partner_id', (string) $contact->partner_id)
                ->where('whatsapp_normalized', $phone)
                ->where('id', '!=', $contactId)
                ->whereNull('deleted_at')
                ->exists();
            if ($conflict) throw new \RuntimeException('Este WhatsApp ya pertenece a otro contacto de la organización.', 409);

            DB::table('contacts')->where('id', $contactId)->update([
                'whatsapp' => $phone,
                'whatsapp_normalized' => $phone,
                'whatsapp_status' => 'VERIFIED',
                'whatsapp_verified_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('campaign_contacts')->where('campaign_id', $campaignId)->where('contact_id', $contactId)->update([
                'whatsapp_capture_status' => 'CONFIRMED',
                'whatsapp_confirmed_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('timeline_activities')->insert([
                'id' => 'ACT_WA_'.strtoupper((string) Str::ulid()),
                'partner_id' => (string) $contact->partner_id,
                'contact_id' => $contactId,
                'type' => 'WHATSAPP_CONFIRMED',
                'description' => 'Se confirmó manualmente el WhatsApp del prospecto durante el evento.',
                'metadata' => json_encode(['campaign_id' => $campaignId, 'source' => 'RHINO_MOBILE_MANUAL'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'actor_id' => $actorId,
                'created_at' => now(),
            ]);
        }, 3);

        return $this->ensure($campaignId, $contactId);
    }

    /** @return array<string,mixed> */
    public function publicContext(string $token): array
    {
        $row = $this->byToken($token);
        $publicCode = $this->codes->ensure((string) $row->contact_id);
        $businessPhone = $this->businessPhone();
        if ($businessPhone === '') {
            throw new \RuntimeException('El número de WhatsApp de RHINO no está configurado.', 503);
        }

        $firstName = trim((string) $row->name);
        $firstName = preg_split('/\s+/', $firstName)[0] ?? 'Prospecto';
        $message = 'Hola, soy '.$firstName.'. Mi código RHINO es '.$publicCode.'. AUTORIZO recibir mi informe de pesaje y mi plan alimenticio por WhatsApp.';

        return [
            'token' => $token,
            'first_name' => $firstName,
            'campaign_name' => (string) $row->campaign_name,
            'public_code' => $publicCode,
            'status' => ((string) ($row->whatsapp_status ?? 'PENDING')) === 'VERIFIED' ? 'CONFIRMED' : (string) ($row->whatsapp_capture_status ?? 'PENDING'),
            'business_name' => (string) config('rhino.personal_reports.whatsapp_contact_name', 'RHINO CRM'),
            'business_phone' => $businessPhone,
            'message' => $message,
            'vcard_url' => route('whatsapp-connect.vcard', ['token' => $token]),
            'whatsapp_url' => route('whatsapp-connect.whatsapp', ['token' => $token]),
            'countdown_seconds' => 300,
        ];
    }

    public function vcard(string $token): string
    {
        $context = $this->publicContext($token);
        DB::table('campaign_contacts')->where('whatsapp_capture_token', $token)->update([
            'whatsapp_vcard_downloaded_at' => now(),
            'updated_at' => now(),
        ]);
        $phone = '+'.$context['business_phone'];
        $name = $this->escapeVcard((string) $context['business_name']);
        return implode("\r\n", [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'FN:'.$name,
            'N:'.$name.';;;;',
            'ORG:'.$name,
            'TEL;TYPE=CELL,VOICE:'.$phone,
            'NOTE:Contacto oficial para informes de pesaje y planes alimenticios RHINO.',
            'END:VCARD',
            '',
        ]);
    }

    public function whatsappRedirect(string $token): string
    {
        $context = $this->publicContext($token);
        DB::table('campaign_contacts')->where('whatsapp_capture_token', $token)->update([
            'whatsapp_opened_at' => now(),
            'updated_at' => now(),
        ]);
        return 'https://wa.me/'.$context['business_phone'].'?text='.rawurlencode((string) $context['message']);
    }

    /** @return array{claimed:bool,conflict:bool,contact_id:string|null,partner_id:string|null} */
    public function claimPendingByPublicCode(string $publicCode, string $phone, string $receivedAt): array
    {
        $contact = DB::table('contacts')->where('public_code', $publicCode)->whereNull('deleted_at')->lockForUpdate()->first();
        if ($contact === null) return ['claimed' => false, 'conflict' => false, 'contact_id' => null, 'partner_id' => null];

        $contactId = (string) $contact->id;
        $partnerId = (string) $contact->partner_id;
        if ((string) ($contact->whatsapp_status ?? 'VERIFIED') === 'VERIFIED') {
            return ['claimed' => false, 'conflict' => false, 'contact_id' => $contactId, 'partner_id' => $partnerId];
        }

        $conflict = DB::table('contacts')
            ->where('partner_id', $partnerId)
            ->where('whatsapp_normalized', $phone)
            ->where('id', '!=', $contactId)
            ->whereNull('deleted_at')
            ->exists();
        if ($conflict) return ['claimed' => false, 'conflict' => true, 'contact_id' => $contactId, 'partner_id' => $partnerId];

        DB::table('contacts')->where('id', $contactId)->update([
            'whatsapp' => $phone,
            'whatsapp_normalized' => $phone,
            'whatsapp_status' => 'VERIFIED',
            'whatsapp_verified_at' => $receivedAt,
            'updated_at' => now(),
        ]);
        DB::table('campaign_contacts')->where('contact_id', $contactId)->update([
            'whatsapp_capture_status' => 'CONFIRMED',
            'whatsapp_confirmed_at' => $receivedAt,
            'updated_at' => now(),
        ]);

        return ['claimed' => true, 'conflict' => false, 'contact_id' => $contactId, 'partner_id' => $partnerId];
    }

    private function participant(string $campaignId, string $contactId): object
    {
        $row = DB::table('campaign_contacts as cc')
            ->join('contacts as c', 'c.id', '=', 'cc.contact_id')
            ->join('campaigns as campaign', 'campaign.id', '=', 'cc.campaign_id')
            ->where('cc.campaign_id', $campaignId)
            ->where('cc.contact_id', $contactId)
            ->whereNull('campaign.deleted_at')
            ->select('cc.*', 'c.partner_id as contact_partner_id', 'c.name', 'c.public_code', 'c.whatsapp_status', 'c.whatsapp_verified_at', 'campaign.name as campaign_name', 'campaign.status as campaign_status')
            ->first();
        if ($row === null) throw new \RuntimeException('La persona no pertenece a este evento.', 404);
        return $row;
    }

    private function byToken(string $token): object
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 80) throw new \RuntimeException('Enlace no válido.', 404);
        $row = DB::table('campaign_contacts as cc')
            ->join('contacts as c', 'c.id', '=', 'cc.contact_id')
            ->join('campaigns as campaign', 'campaign.id', '=', 'cc.campaign_id')
            ->where('cc.whatsapp_capture_token', $token)
            ->whereNull('campaign.deleted_at')
            ->select('cc.*', 'c.name', 'c.public_code', 'c.whatsapp_status', 'campaign.name as campaign_name')
            ->first();
        if ($row === null) throw new \RuntimeException('Enlace no válido o vencido.', 404);
        $expiresAt = $this->date($row->whatsapp_capture_expires_at ?? null);
        if ($expiresAt !== null && $expiresAt->isPast()) {
            throw new \RuntimeException('Este enlace venció. Solicita un QR nuevo al equipo RHINO.', 410);
        }
        return $row;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null || trim((string) $value) === '') return null;
        try {
            return CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function businessPhone(): string
    {
        return preg_replace('/\D+/', '', (string) config('rhino.personal_reports.whatsapp_business_phone')) ?? '';
    }

    private function escapeVcard(string $value): string
    {
        return str_replace(["\\", ";", ",", "\r", "\n"], ["\\\\", "\\;", "\\,", '', '\\n'], $value);
    }
}
