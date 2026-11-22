<?php

namespace App\Modules\Integrations\Whatsapp\Repositories;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Integrations\Whatsapp\Contracts\WhatsappMessageRepositoryInterface;
use App\Modules\Integrations\Whatsapp\Domain\Entities\WhatsappMessage;
use App\Support\Database\GuardsTenant;
use App\Support\Database\SqlValue;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class SqlWhatsappMessageRepository implements WhatsappMessageRepositoryInterface
{
    use GuardsTenant;

    public function __construct(private readonly UserContextDTO $context) {}

    public function find(string $id): ?WhatsappMessage
    {
        $row = DB::table('whatsapp_messages')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }
        $this->guardTenant((string) $row->partner_id);
        return $this->map($row);
    }

    public function findByExternalId(string $externalId): ?WhatsappMessage
    {
        $row = DB::table('whatsapp_messages')->where('external_id', $externalId)->first();
        if ($row === null) {
            return null;
        }
        $this->guardTenant((string) $row->partner_id);
        return $this->map($row);
    }

    public function save(WhatsappMessage $message): void
    {
        $this->guardTenant($message->getPartnerId());
        DB::table('whatsapp_messages')->updateOrInsert(['id' => $message->getId()], [
            'partner_id' => $message->getPartnerId(),
            'contact_id' => $message->getContactId(),
            'to_phone_number' => $message->getToPhoneNumber(),
            'template_name' => $message->getTemplateName(),
            'variables' => SqlValue::encode($message->getVariables()),
            'status' => $message->getStatus(),
            'external_id' => $message->getExternalId(),
            'error_message' => $message->getErrorMessage(),
            'created_at' => SqlValue::timestamp($message->getCreatedAt()),
            'updated_at' => $message->getUpdatedAt()?->format('Y-m-d H:i:s') ?? now(),
        ]);
    }

    private function map(object $row): WhatsappMessage
    {
        return new WhatsappMessage(
            (string) $row->id,
            (string) $row->partner_id,
            (string) $row->contact_id,
            (string) $row->to_phone_number,
            (string) $row->template_name,
            SqlValue::json($row->variables),
            (string) $row->status,
            $row->external_id !== null ? (string) $row->external_id : null,
            $row->error_message !== null ? (string) $row->error_message : null,
            SqlValue::date($row->created_at, new DateTimeImmutable()) ?? new DateTimeImmutable(),
            SqlValue::date($row->updated_at),
        );
    }
}
