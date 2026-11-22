<?php

namespace App\Modules\CRM\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Modules\CRM\Events\ContactMeasurementProcessedEvent;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;

final class MeasurementTimelineService
{
    public function __construct(private readonly UserContextDTO $context) {}

    public function record(ContactMeasurementProcessedEvent $event): void
    {
        if ($this->context->partnerId !== $event->partnerId) {
            throw new TenantViolationException($this->context->userId, $event->partnerId);
        }
        $key = "measurement_timeline:v{$event->version}:{$event->eventId}";
        $hash = hash('sha256', $key);
        DB::transaction(function () use ($event, $key, $hash): void {
            if (DB::table('event_consumptions')->where('consumer_key_hash', $hash)->lockForUpdate()->exists()) {
                return;
            }
            DB::table('timeline_activities')->insertOrIgnore([
                'id' => 'TML_MEAS_'.$event->measurementId,
                'partner_id' => $event->partnerId,
                'contact_id' => $event->contactId,
                'type' => 'MEASUREMENT',
                'description' => 'Nueva medición corporal registrada.',
                'metadata' => SqlValue::encode(['measurement_id' => $event->measurementId, 'is_new_contact' => $event->isNewContact]),
                'actor_id' => 'SYSTEM_IOT',
                'created_at' => now(),
            ]);
            DB::table('event_consumptions')->insert([
                'consumer_key_hash' => $hash, 'consumer' => 'measurement_timeline', 'consumer_key' => $key,
                'event_id' => $event->eventId, 'partner_id' => $event->partnerId, 'processed_at' => now(),
            ]);
        }, 3);
    }
}
