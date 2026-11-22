<?php

namespace App\Modules\PersonalReports\Jobs;

use App\Modules\Auth\Services\TenantContextRunner;
use App\Modules\Integrations\Whatsapp\Contracts\WhatsappProviderInterface;
use App\Modules\PersonalReports\Services\PersonalReportPdfService;
use App\Support\Database\SqlValue;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class GenerateAndSendPersonalReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(
        public readonly string $reportId,
        public readonly string $deliveryId,
        public readonly string $partnerId,
        public readonly string $batchId,
    ) {}

    public function handle(TenantContextRunner $runner): void
    {
        $runner->runAsSystem($this->partnerId, function (): void {
            // El motor nutricional requiere contexto de socio. Resuelve sus dependencias
            // después de instalar el contexto SYSTEM, no al construir el job.
            $pdf = app(PersonalReportPdfService::class);
            $whatsapp = app(WhatsappProviderInterface::class);
            $delivery = DB::transaction(function (): ?object {
                $delivery = DB::table('report_deliveries')->where('id', $this->deliveryId)->lockForUpdate()->first();
                if ($delivery === null || (string) $delivery->partner_id !== $this->partnerId) {
                    return null;
                }
                if (in_array((string) $delivery->status, ['SENT', 'DELIVERED', 'READ', 'SKIPPED'], true)) {
                    return $delivery;
                }
                DB::table('report_deliveries')->where('id', $this->deliveryId)->update([
                    'status' => 'PROCESSING',
                    'processing_at' => now(),
                    'last_error' => null,
                    'failed_at' => null,
                    'updated_at' => now(),
                ]);
                DB::table('personal_reports')->where('id', $this->reportId)->update([
                    'status' => 'GENERATING',
                    'last_error' => null,
                    'updated_at' => now(),
                ]);
                return DB::table('report_deliveries')->where('id', $this->deliveryId)->first();
            }, 3);

            if ($delivery === null || in_array((string) $delivery->status, ['SENT', 'DELIVERED', 'READ', 'SKIPPED'], true)) {
                $this->refreshBatch();
                return;
            }

            try {
                $report = DB::table('personal_reports')->where('id', $this->reportId)->first();
                if ($report === null || (string) $report->partner_id !== $this->partnerId) {
                    throw new \RuntimeException('Informe inconsistente o fuera del socio.');
                }
                $contact = DB::table('contacts')->where('id', $report->contact_id)->where('partner_id', $this->partnerId)->whereNull('deleted_at')->first();
                if ($contact === null || (string) $contact->status !== 'ACTIVE') {
                    $this->skip('CONTACT_NOT_ACTIVE', 'El contacto está bloqueado o ya no existe.');
                    $this->refreshBatch();
                    return;
                }
                $link = DB::table('whatsapp_contact_links')
                    ->where('partner_id', $this->partnerId)
                    ->where('contact_id', $report->contact_id)
                    ->first();
                if ($link === null || (string) $link->status !== 'ACTIVE' || $link->report_consent_at === null) {
                    $this->skip('CONSENT_REVOKED', 'El consentimiento de WhatsApp ya no está vigente.');
                    $this->refreshBatch();
                    return;
                }
                if ($this->digits((string) $link->phone_number) !== $this->digits((string) $contact->whatsapp_normalized)) {
                    $this->skip('PHONE_MISMATCH', 'El número verificado ya no coincide con el expediente.');
                    $this->refreshBatch();
                    return;
                }

                $windowHours = max(1, (int) config('rhino.personal_reports.whatsapp_window_hours', 24));
                $windowOpen = $link->last_inbound_at !== null
                    && Carbon::parse((string) $link->last_inbound_at)->gte(now()->subHours($windowHours));
                $templateName = trim((string) config('rhino.personal_reports.whatsapp_template'));
                if (!$windowOpen && $templateName === '') {
                    $this->skip('WINDOW_EXPIRED', 'La ventana de conversación expiró y no existe una plantilla aprobada para enviar el PDF.');
                    $this->refreshBatch();
                    return;
                }

                $file = $pdf->generate($this->reportId);
                $mediaId = $whatsapp->uploadDocument($file['absolute_path'], $file['filename'], 'application/pdf', $this->partnerId);
                $caption = 'Hola '.trim((string) $contact->name).'. Tu informe RHINO está listo e incluye una alimentación recomendada de orientación general. No sustituye valoración médica o nutricional. Código: '.(string) $report->public_code;
                $externalId = $windowOpen
                    ? $whatsapp->sendDocument((string) $link->phone_number, $mediaId, $file['filename'], $caption, $this->partnerId)
                    : $whatsapp->sendDocumentTemplate(
                        (string) $link->phone_number,
                        $templateName,
                        $mediaId,
                        $file['filename'],
                        ['1' => (string) $contact->name, '2' => (string) $report->public_code],
                        (string) config('rhino.personal_reports.whatsapp_template_language', 'es_MX'),
                        $this->partnerId,
                    );

                DB::transaction(function () use ($report, $contact, $link, $mediaId, $externalId, $templateName, $windowOpen): void {
                    DB::table('report_deliveries')->where('id', $this->deliveryId)->update([
                        'status' => 'SENT',
                        'external_id' => $externalId,
                        'media_id' => $mediaId,
                        'sent_at' => now(),
                        'skip_reason' => null,
                        'last_error' => null,
                        'updated_at' => now(),
                    ]);
                    DB::table('personal_reports')->where('id', $this->reportId)->update([
                        'status' => 'READY',
                        'last_error' => null,
                        'updated_at' => now(),
                    ]);
                    DB::table('nutrition_plan_versions')->where('personal_report_id', $this->reportId)->update([
                        'delivery_status' => 'SENT',
                        'updated_at' => now(),
                    ]);
                    DB::table('whatsapp_messages')->updateOrInsert(
                        ['id' => 'WAM_RPT_'.strtoupper(substr(hash('sha256', $this->deliveryId), 0, 28))],
                        [
                            'partner_id' => $this->partnerId,
                            'contact_id' => $report->contact_id,
                            'direction' => 'OUTBOUND',
                            'message_type' => 'DOCUMENT',
                            'to_phone_number' => $link->phone_number,
                            'template_name' => $windowOpen ? '__DIRECT_DOCUMENT__' : $templateName,
                            'variables' => SqlValue::encode([
                                'report_id' => $this->reportId,
                                'delivery_id' => $this->deliveryId,
                                'public_code' => $report->public_code,
                            ]),
                            'status' => 'SENT',
                            'external_id' => $externalId,
                            'media_id' => $mediaId,
                            'error_message' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    );
                    DB::table('timeline_activities')->insertOrIgnore([
                        'id' => 'TML_RPT_'.strtoupper(substr(hash('sha256', $this->deliveryId), 0, 24)),
                        'partner_id' => $this->partnerId,
                        'contact_id' => $report->contact_id,
                        'type' => 'PERSONAL_REPORT_SENT',
                        'description' => 'Informe de pesaje con alimentación recomendada enviado por WhatsApp.',
                        'metadata' => SqlValue::encode([
                            'campaign_id' => $report->campaign_id,
                            'measurement_id' => $report->measurement_id,
                            'report_id' => $this->reportId,
                            'whatsapp_message_id' => $externalId,
                        ]),
                        'actor_id' => 'SYSTEM_REPORTS',
                        'created_at' => now(),
                    ]);
                }, 3);

                $this->refreshBatch();
            } catch (\Throwable $exception) {
                DB::transaction(function () use ($exception): void {
                    DB::table('report_deliveries')->where('id', $this->deliveryId)->update([
                        'status' => 'FAILED',
                        'last_error' => mb_substr($exception->getMessage(), 0, 1200),
                        'failed_at' => now(),
                        'updated_at' => now(),
                    ]);
                    DB::table('personal_reports')->where('id', $this->reportId)->update([
                        'status' => 'FAILED',
                        'last_error' => mb_substr($exception->getMessage(), 0, 1200),
                        'updated_at' => now(),
                    ]);
                }, 3);
                $this->refreshBatch();
                throw $exception;
            }
        });
    }


    private function refreshBatch(): void
    {
        $batch = DB::table('event_report_batches')->where('id', $this->batchId)->first();
        if ($batch === null) {
            return;
        }
        $counts = DB::table('report_deliveries')
            ->where('batch_id', $this->batchId)
            ->selectRaw("SUM(CASE WHEN status = 'QUEUED' THEN 1 ELSE 0 END) as queued_count")
            ->selectRaw("SUM(CASE WHEN status = 'PROCESSING' THEN 1 ELSE 0 END) as processing_count")
            ->selectRaw("SUM(CASE WHEN status IN ('SENT','DELIVERED','READ') THEN 1 ELSE 0 END) as sent_count")
            ->selectRaw("SUM(CASE WHEN status IN ('DELIVERED','READ') THEN 1 ELSE 0 END) as delivered_count")
            ->selectRaw("SUM(CASE WHEN status = 'READ' THEN 1 ELSE 0 END) as read_count")
            ->selectRaw("SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) as failed_count")
            ->selectRaw("SUM(CASE WHEN status = 'SKIPPED' THEN 1 ELSE 0 END) as skipped_count")
            ->first();
        $pending = (int) ($counts->queued_count ?? 0) + (int) ($counts->processing_count ?? 0);
        DB::table('event_report_batches')->where('id', $this->batchId)->update([
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

    private function skip(string $reason, string $message): void
    {
        DB::transaction(function () use ($reason, $message): void {
            DB::table('report_deliveries')->where('id', $this->deliveryId)->update([
                'status' => 'SKIPPED',
                'skip_reason' => $reason,
                'last_error' => $message,
                'updated_at' => now(),
            ]);
            DB::table('personal_reports')->where('id', $this->reportId)->update([
                'status' => 'SKIPPED',
                'last_error' => $message,
                'updated_at' => now(),
            ]);
        }, 3);
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
