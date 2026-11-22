<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Modules\CRM\Events\ProspectStageChangedEvent;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;

final class FunnelAnalyticsProjector
{
    public function __construct(private readonly UserContextDTO $context) {}

    public function project(ProspectStageChangedEvent $event): void
    {
        if ($this->context->partnerId !== $event->partnerId) {
            throw new TenantViolationException($this->context->userId, $event->partnerId);
        }
        $period = now()->format('Y-m');
        $consumerKey = "funnel_analytics:v{$event->version}:{$event->eventId}";
        $hash = hash('sha256', $consumerKey);

        DB::transaction(function () use ($event, $period, $consumerKey, $hash): void {
            if (DB::table('event_consumptions')->where('consumer_key_hash', $hash)->lockForUpdate()->exists()) {
                return;
            }
            $contact = DB::table('contacts')->where('id', $event->contactId)->first();
            $scopes = [[null, null]];
            if ($contact?->origin_team_id !== null) {
                $scopes[] = [(string) $contact->origin_team_id, null];
            }
            if ($contact?->assigned_advisor_id !== null) {
                $scopes[] = [null, (string) $contact->assigned_advisor_id];
            }

            foreach ($scopes as [$teamId, $userId]) {
                $query = DB::table('funnel_analytics')->where('partner_id', $event->partnerId)->where('period', $period);
                $teamId === null ? $query->whereNull('team_id') : $query->where('team_id', $teamId);
                $userId === null ? $query->whereNull('user_id') : $query->where('user_id', $userId);
                $row = $query->lockForUpdate()->first();
                if ($row === null) {
                    DB::table('funnel_analytics')->insert([
                        'partner_id' => $event->partnerId,
                        'team_id' => $teamId,
                        'user_id' => $userId,
                        'period' => $period,
                        'stage_entries' => SqlValue::encode([]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $row = $query->first();
                }
                $entries = SqlValue::json($row?->stage_entries);
                $stage = strtolower($event->newStage);
                $entries[$stage] = (int) ($entries[$stage] ?? 0) + 1;
                $update = ['stage_entries' => SqlValue::encode($entries), 'updated_at' => now()];
                if ($event->newStage === 'CLOSED') {
                    $update['closed_leads'] = DB::raw('closed_leads + 1');
                }
                if ($event->newStage === 'LOST') {
                    $update['lost_leads'] = DB::raw('lost_leads + 1');
                }
                $target = DB::table('funnel_analytics')->where('partner_id', $event->partnerId)->where('period', $period);
                $teamId === null ? $target->whereNull('team_id') : $target->where('team_id', $teamId);
                $userId === null ? $target->whereNull('user_id') : $target->where('user_id', $userId);
                $target->update($update);
            }

            DB::table('event_consumptions')->insert([
                'consumer_key_hash' => $hash,
                'consumer' => 'funnel_analytics',
                'consumer_key' => $consumerKey,
                'event_id' => $event->eventId,
                'partner_id' => $event->partnerId,
                'result' => 'PROJECTED',
                'processed_at' => now(),
            ]);
        }, 3);
    }
}
