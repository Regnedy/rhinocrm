<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CampaignActivityService
{
    public function __construct(private readonly UserContextDTO $context) {}

    public function record(
        string $campaignId,
        string $campaignPartnerId,
        string $type,
        ?string $contactId = null,
        array $metadata = [],
    ): void {
        if (!DB::getSchemaBuilder()->hasTable('campaign_activity')) return;
        $partnerId = DB::table('users')->where('id', $this->context->userId)->value('partner_id');
        DB::table('campaign_activity')->insert([
            'id' => 'EVA_'.strtoupper((string) Str::ulid()),
            'campaign_id' => $campaignId,
            'campaign_partner_id' => $campaignPartnerId,
            'actor_user_id' => $this->context->userId,
            'actor_partner_id' => $partnerId,
            'activity_type' => strtoupper($type),
            'contact_id' => $contactId,
            'metadata' => SqlValue::encode($metadata),
            'created_at' => now(),
        ]);
    }

    public function list(string $campaignId, int $limit = 100): array
    {
        if (!DB::getSchemaBuilder()->hasTable('campaign_activity')) return [];
        return DB::table('campaign_activity as activity')
            ->leftJoin('users as actor', 'actor.id', '=', 'activity.actor_user_id')
            ->leftJoin('contacts as contact', 'contact.id', '=', 'activity.contact_id')
            ->where('activity.campaign_id', $campaignId)
            ->orderByDesc('activity.created_at')
            ->limit(min(300, max(1, $limit)))
            ->select('activity.*', 'actor.name as actor_name', 'contact.name as contact_name')
            ->get()->map(static function (object $row): array {
                $data = (array) $row;
                $data['metadata'] = SqlValue::json($row->metadata ?? null);
                return $data;
            })->all();
    }
}
