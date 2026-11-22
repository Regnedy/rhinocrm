<?php

namespace App\Modules\CRM\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Modules\CRM\Contracts\ContactRepositoryInterface;
use App\Modules\CRM\Domain\Services\SalesStageTransitionPolicy;
use App\Support\Database\SqlValue;
use App\Support\Database\TeamAccessScope;
use Illuminate\Support\Facades\DB;

final class PipelineService
{
    public function __construct(
        private readonly ContactRepositoryInterface $contacts,
        private readonly SalesStageTransitionPolicy $policy,
        private readonly UserContextDTO $context,
    ) {}

    public function stages(): array
    {
        return $this->policy->catalog();
    }

    public function list(?string $stage, int $limit = 100): array
    {
        $advisor = $this->context->role === 'ADVISOR' ? $this->context->userId : null;
        return $this->contacts->getPipeline($stage, $advisor, $limit);
    }

    public function changeStage(string $contactId, string $newStage, ?string $lossReason, string $idempotencyKey): void
    {
        $partnerId = $this->context->partnerId ?? throw new \RuntimeException('Contexto sin socio.', 403);
        if (trim($idempotencyKey) === '') {
            throw new \InvalidArgumentException('X-Idempotency-Key es obligatorio.');
        }
        $commandHash = hash('sha256', $partnerId.'|stage|'.$contactId.'|'.$idempotencyKey);
        $eventId = 'EVT_STAGE_'.substr($commandHash, 0, 24);

        DB::transaction(function () use ($contactId, $newStage, $lossReason, $partnerId, $commandHash, $eventId): void {
            if (DB::table('command_idempotency')->where('key_hash', $commandHash)->lockForUpdate()->exists()) {
                return;
            }
            $contact = DB::table('contacts')->where('id', $contactId)->lockForUpdate()->first();
            if ($contact === null) {
                throw new \RuntimeException('Contacto no encontrado.', 404);
            }
            if ((string) $contact->partner_id !== $partnerId) {
                throw new TenantViolationException($this->context->userId, (string) $contact->partner_id);
            }
            if ((string) $contact->status !== 'ACTIVE') {
                throw new \RuntimeException('El contacto está bloqueado.', 403);
            }
            if ($this->context->role === 'ADVISOR' && (string) $contact->assigned_advisor_id !== $this->context->userId) {
                throw new \RuntimeException('El contacto no está asignado a este asesor.', 403);
            }
            if (!(new TeamAccessScope($this->context))->canAccessContact($contact)) {
                throw new \RuntimeException('El contacto está fuera del equipo autorizado.', 403);
            }
            $previous = (string) ($contact->sales_stage ?? 'NEW');
            $this->policy->assertAllowed($previous, $newStage);
            $updates = [
                'sales_stage' => $newStage,
                'loss_reason' => $newStage === 'LOST' ? $lossReason : null,
                'updated_at' => now(),
            ];
            if ($newStage === 'CLOSED') {
                $updates['lifecycle_stage'] = 'CUSTOMER';
            } elseif ($newStage === 'NEW' && (string) $contact->lifecycle_stage === 'CUSTOMER') {
                $updates['lifecycle_stage'] = 'PROSPECT';
            }
            DB::table('contacts')->where('id', $contactId)->update($updates);
            if ($newStage === 'CLOSED') {
                $attributionCampaignId = $contact->origin_campaign_id !== null
                    ? (string) $contact->origin_campaign_id
                    : DB::table('campaign_contacts')
                        ->where('contact_id', $contactId)
                        ->where('partner_id', $partnerId)
                        ->orderByDesc('last_seen_at')
                        ->value('campaign_id');
                if ($attributionCampaignId !== null) {
                    DB::table('campaign_contacts')
                        ->where('campaign_id', $attributionCampaignId)
                        ->where('contact_id', $contactId)
                        ->where('partner_id', $partnerId)
                        ->whereNull('converted_at')
                        ->update(['converted_at' => now(), 'updated_at' => now()]);
                }
            }
            DB::table('timeline_activities')->insertOrIgnore([
                'id' => 'TML_'.$eventId,
                'partner_id' => $partnerId,
                'contact_id' => $contactId,
                'type' => 'STAGE_CHANGE',
                'description' => "Etapa comercial cambió de {$previous} a {$newStage}.",
                'metadata' => SqlValue::encode(['from' => $previous, 'to' => $newStage, 'loss_reason' => $lossReason]),
                'actor_id' => $this->context->userId,
                'created_at' => now(),
            ]);
            DB::table('event_outbox')->insertOrIgnore([
                'id' => $eventId,
                'event_type' => 'PROSPECT_STAGE_CHANGED',
                'event_version' => 1,
                'partner_id' => $partnerId,
                'payload' => SqlValue::encode([
                    'contact_id' => $contactId,
                    'previous_stage' => $previous,
                    'new_stage' => $newStage,
                    'actor_id' => $this->context->userId,
                ]),
                'status' => 'PENDING',
                'attempt_count' => 0,
                'available_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('command_idempotency')->insert([
                'key_hash' => $commandHash,
                'partner_id' => $partnerId,
                'type' => 'CHANGE_STAGE',
                'resource_id' => $contactId,
                'command_hash' => $commandHash,
                'event_id' => $eventId,
                'created_at' => now(),
            ]);
        }, 3);
    }
}
