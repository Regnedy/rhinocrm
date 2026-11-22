<?php

namespace App\Modules\CRM\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Modules\CRM\Events\AdvisorAssignedEvent;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;

final class InitialFollowUpService
{
    public function __construct(private readonly UserContextDTO $context) {}

    public function createFor(AdvisorAssignedEvent $event): void
    {
        if ($this->context->partnerId !== $event->partnerId) {
            throw new TenantViolationException($this->context->userId, $event->partnerId);
        }
        $key = "initial_task:v{$event->version}:{$event->eventId}";
        $hash = hash('sha256', $key);
        $taskId = 'TSK_'.strtoupper(substr(hash('sha256', $event->eventId), 0, 20));

        DB::transaction(function () use ($event, $key, $hash, $taskId): void {
            if (DB::table('event_consumptions')->where('consumer_key_hash', $hash)->lockForUpdate()->exists()) {
                return;
            }
            $contact = DB::table('contacts')->where('id', $event->contactId)->lockForUpdate()->first();
            if ($contact === null) {
                return;
            }
            if ((string) $contact->partner_id !== $event->partnerId) {
                throw new TenantViolationException($this->context->userId, (string) $contact->partner_id);
            }
            $teamId = DB::table('team_members')->where('partner_id', $event->partnerId)->where('user_id', $event->advisorId)->where('status', 'ACTIVE')->value('team_id');
            DB::table('tasks')->insertOrIgnore([
                'id' => $taskId,
                'partner_id' => $event->partnerId,
                'contact_id' => $event->contactId,
                'assigned_user_id' => $event->advisorId,
                'team_id' => $teamId,
                'type' => 'FOLLOWUP',
                'title' => 'Primer seguimiento del nuevo prospecto',
                'status' => 'PENDING',
                'due_at' => now()->addHours(2),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('timeline_activities')->insertOrIgnore([
                'id' => 'TML_'.$taskId,
                'partner_id' => $event->partnerId,
                'contact_id' => $event->contactId,
                'type' => 'TASK_CREATED',
                'description' => 'Se creó el primer seguimiento con SLA de 2 horas.',
                'metadata' => SqlValue::encode(['task_id' => $taskId, 'advisor_id' => $event->advisorId]),
                'actor_id' => 'SYSTEM_CRM',
                'created_at' => now(),
            ]);
            DB::table('event_consumptions')->insert([
                'consumer_key_hash' => $hash, 'consumer' => 'initial_task', 'consumer_key' => $key,
                'event_id' => $event->eventId, 'partner_id' => $event->partnerId, 'processed_at' => now(),
            ]);
        }, 3);
    }
}
