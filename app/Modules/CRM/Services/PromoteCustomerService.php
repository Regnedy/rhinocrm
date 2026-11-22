<?php

namespace App\Modules\CRM\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Modules\Sales\Events\SaleCompletedEvent;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;

final class PromoteCustomerService
{
    public function __construct(private readonly UserContextDTO $context) {}

    public function handle(SaleCompletedEvent $event): void
    {
        if ($this->context->partnerId !== $event->partnerId) {
            throw new TenantViolationException($this->context->userId, $event->partnerId);
        }
        $key = "promote_customer:v{$event->version}:{$event->eventId}";
        $hash = hash('sha256', $key);
        DB::transaction(function () use ($event, $key, $hash): void {
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
            DB::table('contacts')->where('id', $event->contactId)->update([
                'lifecycle_stage' => 'CUSTOMER', 'sales_stage' => 'CLOSED', 'updated_at' => now(),
            ]);
            $attributionCampaignId = $event->originCampaignId;
            if ($attributionCampaignId === null) {
                $attributionCampaignId = DB::table('campaign_contacts')
                    ->where('contact_id', $event->contactId)
                    ->where('partner_id', $event->partnerId)
                    ->orderByDesc('last_seen_at')
                    ->value('campaign_id');
            }
            if ($attributionCampaignId !== null) {
                DB::table('campaign_contacts')
                    ->where('campaign_id', $attributionCampaignId)
                    ->where('contact_id', $event->contactId)
                    ->where('partner_id', $event->partnerId)
                    ->whereNull('converted_at')
                    ->update(['converted_at' => now(), 'sale_id' => $event->saleId, 'updated_at' => now()]);
            }
            DB::table('timeline_activities')->insertOrIgnore([
                'id' => 'TML_'.$event->eventId,
                'partner_id' => $event->partnerId,
                'contact_id' => $event->contactId,
                'type' => 'SALE_COMPLETED',
                'description' => 'Venta completada; contacto promovido a cliente.',
                'metadata' => SqlValue::encode(['sale_id' => $event->saleId, 'revenue_minor' => $event->totalRevenueMinor, 'currency' => $event->currency]),
                'actor_id' => $event->advisorId,
                'created_at' => now(),
            ]);
            DB::table('event_consumptions')->insert([
                'consumer_key_hash' => $hash, 'consumer' => 'promote_customer', 'consumer_key' => $key,
                'event_id' => $event->eventId, 'partner_id' => $event->partnerId, 'processed_at' => now(),
            ]);
        }, 3);
    }
}
