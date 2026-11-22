<?php
namespace App\Modules\CRM\Events;
use Illuminate\Foundation\Events\Dispatchable;
final class ContactMeasurementProcessedEvent
{
 use Dispatchable;
 public function __construct(public readonly string $eventId,public readonly string $partnerId,public readonly string $contactId,public readonly string $measurementId,public readonly bool $isNewContact,public readonly int $version=1){}
}
