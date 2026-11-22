<?php
namespace App\Modules\CRM\Events;
use Illuminate\Foundation\Events\Dispatchable;
final class AdvisorAssignedEvent
{
 use Dispatchable;
 public function __construct(public readonly string $eventId,public readonly string $partnerId,public readonly string $contactId,public readonly string $advisorId,public readonly string $locationId,public readonly int $version=1){}
}
