<?php
namespace App\Modules\CRM\Events;
use Illuminate\Foundation\Events\Dispatchable;
final class ProspectStageChangedEvent
{
 use Dispatchable;
 public function __construct(public readonly string $eventId,public readonly string $partnerId,public readonly string $contactId,public readonly string $previousStage,public readonly string $newStage,public readonly ?string $actorId,public readonly int $version=1){}
}
