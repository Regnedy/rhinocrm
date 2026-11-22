<?php

namespace App\Modules\CRM\Listeners;

use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Modules\Auth\Services\TenantContextRunner;
use App\Modules\CRM\Services\ContactMeasurementIngestionService;
use App\Modules\IoT\Contracts\MeasurementRepositoryInterface;
use App\Modules\IoT\Events\MeasurementReceivedEvent;
use App\Modules\IoT\Services\IntakePayloadCipher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

final class ProcessMeasurementReceived implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly TenantContextRunner $runner) {}

    public function handle(MeasurementReceivedEvent $event): void
    {
        $this->runner->runAsSystem($event->partnerId, function ($context) use ($event): void {
            if (DB::table('crm_measurement_processing')->where('measurement_id', $event->measurementId)->exists()) {
                return;
            }

            $measurement = app(MeasurementRepositoryInterface::class)->findById($event->measurementId);
            if ($measurement === null) {
                throw new \RuntimeException('La medición del evento no existe.', 404);
            }

            $intake = DB::table('measurement_intakes')->where('measurement_id', $event->measurementId)->first();
            if ($intake === null) {
                throw new \RuntimeException('No existe la ficha temporal de la medición.', 409);
            }
            if ((string) $intake->partner_id !== $event->partnerId) {
                throw new TenantViolationException('SYSTEM', (string) $intake->partner_id);
            }
            if ((string) $intake->status !== 'PENDING' || empty($intake->patient_payload_encrypted)) {
                throw new \RuntimeException('La ficha temporal no está disponible para procesamiento.', 409);
            }

            $patientData = app(IntakePayloadCipher::class)->decrypt((string) $intake->patient_payload_encrypted);
            app(ContactMeasurementIngestionService::class)->process($measurement, $patientData, $context);
        });
    }
}
