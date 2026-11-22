<?php

namespace App\Modules\Workflows\Listeners;

use App\Modules\Auth\Services\TenantContextRunner;
use App\Modules\CRM\Contracts\ContactRepositoryInterface;
use App\Modules\CRM\Events\ContactMeasurementProcessedEvent;
use App\Modules\Workflows\Services\WorkflowEngineService;
use Illuminate\Contracts\Queue\ShouldQueue;

final class TriggerMeasurementWorkflows implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly TenantContextRunner $runner) {}

    public function handle(ContactMeasurementProcessedEvent $event): void
    {
        $this->runner->runAsSystem($event->partnerId, function () use ($event): void {
            $contact = app(ContactRepositoryInterface::class)->find($event->contactId);
            if ($contact === null) {
                return;
            }

            app(WorkflowEngineService::class)->triggerWorkflows(
                partnerId: $event->partnerId,
                trigger: 'CONTACT_MEASUREMENT_PROCESSED',
                contact: $contact,
                eventId: $event->eventId,
                eventContext: ['event' => [
                    'is_new_contact' => $event->isNewContact,
                    'measurement_id' => $event->measurementId,
                ]],
            );
        });
    }
}
