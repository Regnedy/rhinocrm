<?php
namespace App\Modules\Integrations\Whatsapp\Events;
use Illuminate\Foundation\Events\Dispatchable;
final class WhatsappMessageStatusChangedEvent
{use Dispatchable;public function __construct(public readonly string $eventId,public readonly string $partnerId,public readonly string $messageId,public readonly string $contactId,public readonly string $status,public readonly string $previousStatus,public readonly string $templateName,public readonly ?string $errorMessage,public readonly int $version=1){}}
