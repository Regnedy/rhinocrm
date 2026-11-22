<?php

namespace App\Modules\Workflows\Listeners;

use App\Modules\Auth\Services\TenantContextRunner;
use App\Modules\CRM\Contracts\ContactRepositoryInterface;
use App\Modules\CRM\Events\AdvisorAssignedEvent;
use App\Modules\Workflows\Services\WorkflowEngineService;
use Illuminate\Contracts\Queue\ShouldQueue;

final class TriggerAdvisorAssignedWorkflows implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly TenantContextRunner $runner) {}

    public function handle(AdvisorAssignedEvent $event): void
    {
        $this->runner->runAsSystem($event->partnerId, function () use ($event): void {
            $contact = app(ContactRepositoryInterface::class)->find($event->contactId);
            if ($contact === null) {
                return;
            }

            app(WorkflowEngineService::class)->triggerWorkflows(
                partnerId: $event->partnerId,
                trigger: 'ADVISOR_ASSIGNED',
                contact: $contact,
                eventId: $event->eventId,
                eventContext: ['event' => [
                    'advisor_id' => $event->advisorId,
                    'location_id' => $event->locationId,
                ]],
            );
        });
    }
}
