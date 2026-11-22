<?php

namespace App\Modules\CRM\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;

final class LeadAssignmentService
{
    public function __construct(private readonly UserContextDTO $context) {}

    public function assignFromEvent(string $sourceEventId, int $version, string $partnerId, string $contactId): void
    {
        if ($this->context->partnerId !== $partnerId) {
            throw new TenantViolationException($this->context->userId, $partnerId);
        }
        $consumerKey = "assign_advisor:v{$version}:{$sourceEventId}";
        $consumerHash = hash('sha256', $consumerKey);
        $derivedEventId = 'EVT_ADVISOR_'.substr(hash('sha256', $sourceEventId), 0, 24);

        DB::transaction(function () use ($consumerKey, $consumerHash, $sourceEventId, $derivedEventId, $partnerId, $contactId): void {
            if (DB::table('event_consumptions')->where('consumer_key_hash', $consumerHash)->lockForUpdate()->exists()) {
                return;
            }
            $contact = DB::table('contacts')->where('id', $contactId)->lockForUpdate()->first();
            if ($contact === null) {
                return;
            }
            if ((string) $contact->partner_id !== $partnerId) {
                throw new TenantViolationException($this->context->userId, (string) $contact->partner_id);
            }
            if ((string) $contact->status !== 'ACTIVE') {
                $this->consume($consumerHash, $consumerKey, $sourceEventId, $partnerId, 'CONTACT_BLOCKED');
                return;
            }

            $source = SqlValue::json($contact->contact_source);
            $locationId = (string) ($source['origin_location_id'] ?? '');
            $teamId = $contact->origin_team_id !== null ? (string) $contact->origin_team_id : null;
            $advisorsQuery = DB::table('users')
                ->where('users.partner_id', $partnerId)
                ->where('users.location_id', $locationId)
                ->whereIn('users.role', ['ADVISOR', 'TEAM_LEADER'])
                ->where('users.status', 'ACTIVE')
                ->whereNull('users.deleted_at');
            if ($teamId !== null) {
                $advisorsQuery->join('team_members', function ($join) use ($teamId): void {
                    $join->on('team_members.user_id', '=', 'users.id')
                        ->where('team_members.team_id', '=', $teamId)
                        ->where('team_members.status', '=', 'ACTIVE');
                });
            }
            $advisorIds = $advisorsQuery->orderBy('users.id')->pluck('users.id')->map('strval')->all();

            $advisorId = $contact->assigned_advisor_id !== null ? (string) $contact->assigned_advisor_id : null;
            $result = 'ALREADY_ASSIGNED';
            if ($advisorId === null && $advisorIds !== []) {
                $scopeHash = hash('sha256', $partnerId.'|'.$locationId.'|'.($teamId ?? 'NO_TEAM'));
                $cursor = DB::table('assignment_cursors')->where('scope_hash', $scopeHash)->lockForUpdate()->first();
                $last = $cursor ? (int) $cursor->cursor : -1;
                $index = ($last + 1) % count($advisorIds);
                $advisorId = $advisorIds[$index];
                DB::table('contacts')->where('id', $contactId)->update([
                    'assigned_advisor_id' => $advisorId,
                    'lifecycle_stage' => (string) $contact->lifecycle_stage === 'CONTACT' ? 'PROSPECT' : $contact->lifecycle_stage,
                    'updated_at' => now(),
                ]);
                DB::table('assignment_cursors')->updateOrInsert(['scope_hash' => $scopeHash], [
                    'partner_id' => $partnerId,
                    'location_id' => $locationId !== '' ? $locationId : null,
                    'team_id' => $teamId,
                    'cursor' => $index,
                    'updated_at' => now(),
                ]);
                DB::table('event_outbox')->insertOrIgnore([
                    'id' => $derivedEventId,
                    'event_type' => 'ADVISOR_ASSIGNED',
                    'event_version' => 1,
                    'partner_id' => $partnerId,
                    'payload' => SqlValue::encode(['contact_id' => $contactId, 'advisor_id' => $advisorId, 'location_id' => $locationId]),
                    'status' => 'PENDING',
                    'attempt_count' => 0,
                    'available_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $result = 'ASSIGNED';
            } elseif ($advisorId === null) {
                $result = 'NO_ADVISOR_AVAILABLE';
            }

            $this->consume($consumerHash, $consumerKey, $sourceEventId, $partnerId, $result);
        }, 3);
    }

    private function consume(string $hash, string $key, string $eventId, string $partnerId, string $result): void
    {
        DB::table('event_consumptions')->insertOrIgnore([
            'consumer_key_hash' => $hash,
            'consumer' => 'assign_advisor',
            'consumer_key' => $key,
            'event_id' => $eventId,
            'partner_id' => $partnerId,
            'result' => $result,
            'processed_at' => now(),
        ]);
    }
}
