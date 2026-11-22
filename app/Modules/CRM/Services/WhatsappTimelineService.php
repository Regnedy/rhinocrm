<?php

namespace App\Modules\CRM\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Modules\Integrations\Whatsapp\Events\WhatsappMessageStatusChangedEvent;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;

final class WhatsappTimelineService
{
    public function __construct(private readonly UserContextDTO $context) {}

    public function record(WhatsappMessageStatusChangedEvent $event): void
    {
        if (!in_array($event->status, ['DELIVERED', 'READ', 'FAILED'], true)) {
            return;
        }
        if ($this->context->partnerId !== $event->partnerId) {
            throw new TenantViolationException($this->context->userId, $event->partnerId);
        }
        $key = "whatsapp_timeline:v{$event->version}:{$event->eventId}";
        $hash = hash('sha256', $key);
        DB::transaction(function () use ($event, $key, $hash): void {
            if (DB::table('event_consumptions')->where('consumer_key_hash', $hash)->lockForUpdate()->exists()) {
                return;
            }
            $description = match ($event->status) {
                'DELIVERED' => 'WhatsApp entregado.',
                'READ' => 'WhatsApp leído por el cliente.',
                'FAILED' => 'Fallo al enviar WhatsApp.',
                default => 'Actualización de WhatsApp.',
            };
            DB::table('timeline_activities')->insertOrIgnore([
                'id' => 'TML_'.$event->eventId,
                'partner_id' => $event->partnerId,
                'contact_id' => $event->contactId,
                'type' => 'WHATSAPP_UPDATE',
                'description' => $description,
                'metadata' => SqlValue::encode([
                    'message_id' => $event->messageId,
                    'status' => $event->status,
                    'template_name' => $event->templateName,
                    'error' => $event->errorMessage,
                ]),
                'actor_id' => 'SYSTEM_WHATSAPP',
                'created_at' => now(),
            ]);
            DB::table('event_consumptions')->insert([
                'consumer_key_hash' => $hash, 'consumer' => 'whatsapp_timeline', 'consumer_key' => $key,
                'event_id' => $event->eventId, 'partner_id' => $event->partnerId, 'processed_at' => now(),
            ]);
        }, 3);
    }
}
