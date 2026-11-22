<?php

namespace App\Modules\PersonalReports\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Modules\PersonalReports\Jobs\GenerateAndSendPersonalReportJob;
use App\Support\Database\TeamAccessScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EventReportService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly ContactPublicCodeService $codes,
    ) {}

    /** @return array<string,mixed> */
    public function summary(string $campaignId): array
    {
        $campaign = $this->authorizedCampaign($campaignId);
        $evaluation = $this->evaluate($campaign);
        $latestBatch = DB::table('event_report_batches')
            ->where('partner_id', $campaign->partner_id)
            ->where('campaign_id', $campaignId)
            ->orderByDesc('created_at')
            ->first();

        return [
            'campaign_id' => $campaignId,
            'eligible_count' => count($evaluation['eligible']),
            'participant_count' => count($evaluation['eligible']) + array_sum($evaluation['reasons']),
            'reasons' => $evaluation['reasons'],
            'reason_labels' => $this->reasonLabels(),
            'latest_batch' => $latestBatch !== null ? $this->batchArray($latestBatch) : null,
            'requirements' => [
                'must_have_measurement' => true,
                'must_have_sent_whatsapp_code' => true,
                'must_have_report_consent' => true,
                'open_conversation_hours' => (int) config('rhino.personal_reports.whatsapp_window_hours', 24),
                'template_for_expired_window' => trim((string) config('rhino.personal_reports.whatsapp_template')) !== '',
                'event_must_be_closed' => true,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function dispatch(string $campaignId): array
    {
        $campaign = $this->authorizedCampaign($campaignId);
        $this->assertCanDispatch($campaign);
        $evaluation = $this->evaluate($campaign);
        $batchId = 'ERB_'.strtoupper((string) Str::ulid());
        $jobs = [];

        DB::transaction(function () use ($campaign, $campaignId, $batchId, $evaluation, &$jobs): void {
            DB::table('event_report_batches')->insert([
                'id' => $batchId,
                'partner_id' => $campaign->partner_id,
                'campaign_id' => $campaignId,
                'requested_by_user_id' => $this->context->userId,
                'status' => $evaluation['eligible'] === [] ? 'COMPLETED' : 'QUEUED',
                'eligible_count' => count($evaluation['eligible']),
                'excluded_count' => array_sum($evaluation['reasons']),
                'queued_count' => count($evaluation['eligible']),
                'sent_count' => 0,
                'delivered_count' => 0,
                'read_count' => 0,
                'failed_count' => 0,
                'skipped_count' => 0,
                'started_at' => $evaluation['eligible'] === [] ? null : now(),
                'completed_at' => $evaluation['eligible'] === [] ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($evaluation['eligible'] as $row) {
                $publicCode = $row->public_code !== null && trim((string) $row->public_code) !== ''
                    ? strtoupper((string) $row->public_code)
                    : $this->codes->ensure((string) $row->contact_id);
                $configuredAlgorithm = trim((string) config('rhino.personal_reports.algorithm_version', NutritionRecommendationService::ALGORITHM_VERSION));
                $algorithm = in_array($configuredAlgorithm, ['', 'rhino-orientation-v1'], true)
                    ? NutritionRecommendationService::ALGORITHM_VERSION
                    : $configuredAlgorithm;
                $reportId = 'PRT_'.strtoupper(substr(hash('sha256', $campaignId.'|'.$row->contact_id.'|'.$row->measurement_id.'|'.$algorithm), 0, 28));
                $deliveryId = 'RDL_'.strtoupper(substr(hash('sha256', $reportId.'|WHATSAPP'), 0, 28));

                DB::table('personal_reports')->updateOrInsert(
                    ['id' => $reportId],
                    [
                        'partner_id' => $campaign->partner_id,
                        'campaign_id' => $campaignId,
                        'contact_id' => $row->contact_id,
                        'measurement_id' => $row->measurement_id,
                        'public_code' => $publicCode,
                        'report_type' => 'WEIGHING_NUTRITION_GUIDANCE',
                        'status' => 'QUEUED',
                        'algorithm_version' => $algorithm,
                        'last_error' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );

                DB::table('report_deliveries')->updateOrInsert(
                    ['id' => $deliveryId],
                    [
                        'batch_id' => $batchId,
                        'report_id' => $reportId,
                        'partner_id' => $campaign->partner_id,
                        'campaign_id' => $campaignId,
                        'contact_id' => $row->contact_id,
                        'channel' => 'WHATSAPP',
                        'recipient' => $row->phone_number,
                        'status' => 'QUEUED',
                        'external_id' => null,
                        'media_id' => null,
                        'skip_reason' => null,
                        'last_error' => null,
                        'queued_at' => now(),
                        'processing_at' => null,
                        'sent_at' => null,
                        'delivered_at' => null,
                        'read_at' => null,
                        'failed_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );

                $jobs[] = [$reportId, $deliveryId, (string) $campaign->partner_id, $batchId];
            }
        }, 3);

        foreach ($jobs as [$reportId, $deliveryId, $partnerId, $jobBatchId]) {
            GenerateAndSendPersonalReportJob::dispatch($reportId, $deliveryId, $partnerId, $jobBatchId);
        }

        $batch = DB::table('event_report_batches')->where('id', $batchId)->first();
        return [
            'batch' => $this->batchArray($batch),
            'eligible_count' => count($evaluation['eligible']),
            'skipped_count' => 0,
            'reasons' => $evaluation['reasons'],
            'message' => count($evaluation['eligible']) > 0
                ? 'Los informes se generarán y enviarán uno por uno mediante la cola.'
                : 'No había participantes elegibles para envío automático.',
        ];
    }

    public function refreshBatch(string $batchId): void
    {
        $batch = DB::table('event_report_batches')->where('id', $batchId)->first();
        if ($batch === null) {
            return;
        }

        $counts = DB::table('report_deliveries')
            ->where('batch_id', $batchId)
            ->selectRaw("SUM(CASE WHEN status = 'QUEUED' THEN 1 ELSE 0 END) as queued_count")
            ->selectRaw("SUM(CASE WHEN status = 'PROCESSING' THEN 1 ELSE 0 END) as processing_count")
            ->selectRaw("SUM(CASE WHEN status IN ('SENT','DELIVERED','READ') THEN 1 ELSE 0 END) as sent_count")
            ->selectRaw("SUM(CASE WHEN status IN ('DELIVERED','READ') THEN 1 ELSE 0 END) as delivered_count")
            ->selectRaw("SUM(CASE WHEN status = 'READ' THEN 1 ELSE 0 END) as read_count")
            ->selectRaw("SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) as failed_count")
            ->selectRaw("SUM(CASE WHEN status = 'SKIPPED' THEN 1 ELSE 0 END) as skipped_count")
            ->first();

        $pending = (int) ($counts->queued_count ?? 0) + (int) ($counts->processing_count ?? 0);
        DB::table('event_report_batches')->where('id', $batchId)->update([
            'status' => $pending > 0 ? 'PROCESSING' : 'COMPLETED',
            'queued_count' => (int) ($counts->queued_count ?? 0),
            'sent_count' => (int) ($counts->sent_count ?? 0),
            'delivered_count' => (int) ($counts->delivered_count ?? 0),
            'read_count' => (int) ($counts->read_count ?? 0),
            'failed_count' => (int) ($counts->failed_count ?? 0),
            'skipped_count' => (int) ($counts->skipped_count ?? 0),
            'completed_at' => $pending > 0 ? null : now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{eligible:array<int,object>,reasons:array<string,int>} */
    private function evaluate(object $campaign): array
    {
        $rows = DB::table('campaign_contacts as cc')
            ->join('contacts as c', 'c.id', '=', 'cc.contact_id')
            ->leftJoin('whatsapp_contact_links as wl', function ($join): void {
                $join->on('wl.contact_id', '=', 'cc.contact_id')->on('wl.partner_id', '=', 'cc.partner_id');
            })
            ->where('cc.campaign_id', $campaign->id)
            ->where('cc.partner_id', $campaign->partner_id)
            ->where('c.status', 'ACTIVE')
            ->whereNull('c.deleted_at')
            ->select(
                'cc.contact_id', 'cc.last_measurement_id', 'c.public_code', 'c.whatsapp_normalized',
                'wl.phone_number', 'wl.status as link_status', 'wl.report_consent_at', 'wl.last_inbound_at'
            )
            ->get();

        $eligible = [];
        $reasons = array_fill_keys(array_keys($this->reasonLabels()), 0);
        $windowHours = max(1, (int) config('rhino.personal_reports.whatsapp_window_hours', 24));
        $hasTemplate = trim((string) config('rhino.personal_reports.whatsapp_template')) !== '';

        foreach ($rows as $row) {
            $measurementId = $row->last_measurement_id !== null ? (string) $row->last_measurement_id : null;
            if ($measurementId === null || !DB::table('measurements')->where('id', $measurementId)->where('campaign_id', $campaign->id)->exists()) {
                $measurementId = DB::table('measurements')
                    ->where('campaign_id', $campaign->id)
                    ->where('contact_id', $row->contact_id)
                    ->orderByDesc('timestamp_local')
                    ->value('id');
                $measurementId = $measurementId !== null ? (string) $measurementId : null;
            }
            if ($measurementId === null) {
                $reasons['NO_MEASUREMENT']++;
                continue;
            }
            if ($row->public_code === null || trim((string) $row->public_code) === '') {
                $reasons['NO_PUBLIC_CODE']++;
                continue;
            }
            if ($row->link_status === null || (string) $row->link_status !== 'ACTIVE') {
                $reasons['NO_WHATSAPP_LINK']++;
                continue;
            }
            if ($row->report_consent_at === null) {
                $reasons['NO_REPORT_CONSENT']++;
                continue;
            }
            if ($row->phone_number === null || $this->digits((string) $row->phone_number) !== $this->digits((string) $row->whatsapp_normalized)) {
                $reasons['PHONE_MISMATCH']++;
                continue;
            }
            if ($row->last_inbound_at === null) {
                $reasons['NO_WHATSAPP_LINK']++;
                continue;
            }
            $windowExpired = Carbon::parse((string) $row->last_inbound_at)->lt(now()->subHours($windowHours));
            if ($windowExpired && !$hasTemplate) {
                $reasons['WINDOW_EXPIRED']++;
                continue;
            }
            $alreadySent = DB::table('personal_reports as pr')
                ->join('report_deliveries as rd', 'rd.report_id', '=', 'pr.id')
                ->where('pr.campaign_id', $campaign->id)
                ->where('pr.contact_id', $row->contact_id)
                ->where('pr.measurement_id', $measurementId)
                ->whereIn('rd.status', ['SENT', 'DELIVERED', 'READ'])
                ->exists();
            if ($alreadySent) {
                $reasons['ALREADY_SENT']++;
                continue;
            }

            $row->measurement_id = $measurementId;
            $row->phone_number = $this->digits((string) $row->phone_number);
            $row->window_expired = $windowExpired;
            $eligible[] = $row;
        }

        return ['eligible' => $eligible, 'reasons' => array_filter($reasons, static fn (int $count): bool => $count > 0)];
    }

    /** @return array<string,string> */
    private function reasonLabels(): array
    {
        return [
            'NO_MEASUREMENT' => 'Sin pesaje vinculado al evento',
            'NO_PUBLIC_CODE' => 'Sin código RHINO',
            'NO_WHATSAPP_LINK' => 'No envió su código por WhatsApp',
            'NO_REPORT_CONSENT' => 'No autorizó recibir el informe',
            'PHONE_MISMATCH' => 'El WhatsApp no coincide con el expediente',
            'WINDOW_EXPIRED' => 'La conversación de WhatsApp expiró y no hay plantilla aprobada',
            'ALREADY_SENT' => 'El informe de este pesaje ya fue enviado',
        ];
    }

    private function authorizedCampaign(string $campaignId): object
    {
        $campaign = DB::table('campaigns')->where('id', $campaignId)->whereNull('deleted_at')->first();
        if ($campaign === null) {
            throw new \RuntimeException('Evento no encontrado.', 404);
        }
        if ((string) $campaign->partner_id !== (string) $this->context->partnerId) {
            throw new TenantViolationException($this->context->userId, (string) $campaign->partner_id);
        }
        if (!$this->canAccess($campaign)) {
            throw new \RuntimeException('Evento fuera de tu ámbito autorizado.', 403);
        }
        return $campaign;
    }

    private function assertCanDispatch(object $campaign): void
    {
        if ((string) $campaign->status !== 'CLOSED') {
            throw new \RuntimeException('Cierra el evento antes de generar y enviar sus informes.', 409);
        }
        if (in_array($this->context->role, ['PARTNER_ADMIN', 'LOCATION_MANAGER'], true)) {
            return;
        }
        if ($this->context->role === 'TEAM_LEADER' && $this->canAccess($campaign)) {
            return;
        }
        throw new \RuntimeException('Solo el socio o coordinador autorizado puede enviar todos los informes del evento.', 403);
    }

    private function canAccess(object $campaign): bool
    {
        if (in_array($this->context->role, ['PARTNER_ADMIN', 'LOCATION_MANAGER'], true)) {
            return true;
        }
        if ((string) ($campaign->responsible_user_id ?? '') === $this->context->userId) {
            return true;
        }
        if (DB::table('campaign_members')->where('campaign_id', $campaign->id)->where('user_id', $this->context->userId)->where('status', 'ACTIVE')->exists()) {
            return true;
        }
        if ($this->context->role === 'TEAM_LEADER' && $campaign->team_id !== null) {
            return (new TeamAccessScope($this->context))->canManageTeam((string) $campaign->team_id);
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function batchArray(?object $batch): array
    {
        if ($batch === null) {
            return [];
        }
        return [
            'id' => (string) $batch->id,
            'status' => (string) $batch->status,
            'eligible_count' => (int) $batch->eligible_count,
            'excluded_count' => (int) ($batch->excluded_count ?? 0),
            'queued_count' => (int) $batch->queued_count,
            'sent_count' => (int) $batch->sent_count,
            'delivered_count' => (int) $batch->delivered_count,
            'read_count' => (int) $batch->read_count,
            'failed_count' => (int) $batch->failed_count,
            'skipped_count' => (int) $batch->skipped_count,
            'created_at' => $batch->created_at,
            'completed_at' => $batch->completed_at,
        ];
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
