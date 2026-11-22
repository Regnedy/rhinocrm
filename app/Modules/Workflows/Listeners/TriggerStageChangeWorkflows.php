<?php

namespace App\Modules\Workflows\Listeners;

use App\Modules\Auth\Services\TenantContextRunner;
use App\Modules\CRM\Contracts\ContactRepositoryInterface;
use App\Modules\CRM\Events\ProspectStageChangedEvent;
use App\Modules\Workflows\Services\WorkflowEngineService;
use Illuminate\Contracts\Queue\ShouldQueue;

final class TriggerStageChangeWorkflows implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly TenantContextRunner $runner) {}

    public function handle(ProspectStageChangedEvent $event): void
    {
        $this->runner->runAsSystem($event->partnerId, function () use ($event): void {
            $contact = app(ContactRepositoryInterface::class)->find($event->contactId);
            if ($contact === null) {
                return;
            }

            app(WorkflowEngineService::class)->triggerWorkflows(
                partnerId: $event->partnerId,
                trigger: 'PROSPECT_STAGE_CHANGED',
                contact: $contact,
                eventId: $event->eventId,
                eventContext: ['event' => [
                    'previous_stage' => $event->previousStage,
                    'new_stage' => $event->newStage,
                    'actor_id' => $event->actorId,
                ]],
            );
        });
    }
}
