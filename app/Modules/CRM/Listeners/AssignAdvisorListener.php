<?php
namespace App\Modules\CRM\Listeners;
use App\Modules\Auth\Services\TenantContextRunner;
use App\Modules\CRM\Events\ContactMeasurementProcessedEvent;
use App\Modules\CRM\Services\LeadAssignmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
final class AssignAdvisorListener implements ShouldQueue
{
 public int $tries=3; public function __construct(private TenantContextRunner $runner){}
 public function handle(ContactMeasurementProcessedEvent $event):void{if(!$event->isNewContact)return;$this->runner->runAsSystem($event->partnerId,function()use($event){app(LeadAssignmentService::class)->assignFromEvent($event->eventId,$event->version,$event->partnerId,$event->contactId);});}
}
