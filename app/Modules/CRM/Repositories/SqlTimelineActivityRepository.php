<?php

namespace App\Modules\CRM\Repositories;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\CRM\Contracts\TimelineActivityRepositoryInterface;
use App\Modules\CRM\Domain\Entities\TimelineActivity;
use App\Support\Database\GuardsTenant;
use App\Support\Database\SqlValue;
use App\Support\Database\TeamAccessScope;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class SqlTimelineActivityRepository implements TimelineActivityRepositoryInterface
{
    use GuardsTenant;

    public function __construct(private readonly UserContextDTO $context) {}

    public function save(TimelineActivity $activity): void
    {
        $this->guardTenant($activity->partnerId);
        DB::table('timeline_activities')->insertOrIgnore([
            'id' => $activity->id,
            'partner_id' => $activity->partnerId,
            'contact_id' => $activity->contactId,
            'type' => $activity->type,
            'description' => $activity->description,
            'metadata' => $activity->metadata === null ? null : SqlValue::encode($activity->metadata),
            'actor_id' => $activity->actorId,
            'created_at' => SqlValue::timestamp($activity->createdAt),
        ]);
    }

    public function listForContact(string $contactId, int $limit = 100): array
    {
        $contact = DB::table('contacts')->where('id', $contactId)->first();
        if ($contact === null) {
            return [];
        }
        $this->guardTenant((string) $contact->partner_id);
        if ($this->context->role === 'ADVISOR' && (string) ($contact->assigned_advisor_id ?? '') !== $this->context->userId) {
            throw new \RuntimeException('No tienes acceso al timeline de este contacto.', 403);
        }
        if (!(new TeamAccessScope($this->context))->canAccessContact($contact)) {
            throw new \RuntimeException('Contacto fuera del equipo autorizado.', 403);
        }
        return DB::table('timeline_activities')
            ->where('partner_id', (string) $contact->partner_id)
            ->where('contact_id', $contactId)
            ->orderByDesc('created_at')
            ->limit(min(max($limit, 1), 200))
            ->get()
            ->map(fn (object $row): TimelineActivity => new TimelineActivity(
                (string) $row->id,
                (string) $row->partner_id,
                (string) $row->contact_id,
                (string) $row->type,
                (string) $row->description,
                $row->metadata !== null ? SqlValue::json($row->metadata) : null,
                $row->actor_id !== null ? (string) $row->actor_id : null,
                SqlValue::date($row->created_at, new DateTimeImmutable()) ?? new DateTimeImmutable(),
            ))
            ->all();
    }
}
