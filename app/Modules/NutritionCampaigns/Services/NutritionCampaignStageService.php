<?php

namespace App\Modules\NutritionCampaigns\Services;

use App\Modules\Movements\Services\MovementService;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;

final class NutritionCampaignStageService
{
    public function __construct(private readonly MovementService $movements) {}

    /** @var array<string,string> */
    public const STAGES = [
        'SELECTED' => 'Seleccionado',
        'INVITATION_PENDING' => 'Invitación pendiente',
        'INVITATION_SENT' => 'Invitación enviada',
        'DELIVERED' => 'Entregada',
        'READ' => 'Leída',
        'RESPONDED' => 'Respondió',
        'CONFIRMED' => 'Confirmó',
        'DECLINED' => 'No confirmó',
        'ATTENDED' => 'Asistió',
        'NO_SHOW' => 'No asistió',
        'REWEIGHED' => 'Nuevo pesaje',
        'TASTING_COMPLETED' => 'Degustación realizada',
        'INTERESTED' => 'Interesado',
        'SALE_COMPLETED' => 'Venta realizada',
        'NO_RESPONSE' => 'Sin respuesta',
        'OPTED_OUT' => 'No desea mensajes',
    ];

    /**
     * @param array<string,mixed> $extraUpdates
     * @return array<string,mixed>
     */
    public function transition(
        string $memberId,
        string $toStage,
        string $source,
        ?string $changedByUserId = null,
        ?string $note = null,
        array $extraUpdates = [],
    ): array {
        $toStage = strtoupper(trim($toStage));
        if (!array_key_exists($toStage, self::STAGES)) {
            throw new \RuntimeException('La etapa solicitada no existe.', 422);
        }

        return DB::transaction(function () use (
            $memberId,
            $toStage,
            $source,
            $changedByUserId,
            $note,
            $extraUpdates,
        ): array {
            $member = DB::table('nutrition_followup_members')
                ->where('id', $memberId)
                ->lockForUpdate()
                ->first();

            if ($member === null) {
                throw new \RuntimeException('Participante de campaña no encontrado.', 404);
            }

            $fromStage = (string) $member->stage;
            $updates = array_merge(
                $this->timestampUpdates($toStage, $member),
                $extraUpdates,
                ['stage' => $toStage, 'updated_at' => now()],
            );

            DB::table('nutrition_followup_members')
                ->where('id', $memberId)
                ->update($updates);

            if ($fromStage !== $toStage || $note !== null) {
                DB::table('nutrition_followup_stage_history')->insert([
                    'campaign_id' => $member->campaign_id,
                    'member_id' => $member->id,
                    'partner_id' => $member->partner_id,
                    'contact_id' => $member->contact_id,
                    'from_stage' => $fromStage,
                    'to_stage' => $toStage,
                    'source' => strtoupper(trim($source)) ?: 'SYSTEM',
                    'changed_by_user_id' => $changedByUserId,
                    'note' => $note,
                    'created_at' => now(),
                ]);

                DB::table('timeline_activities')->insert([
                    'id' => 'TML_NFC_'.strtoupper(substr(hash(
                        'sha256',
                        $member->id.'|'.$toStage.'|'.microtime(true)
                    ), 0, 26)),
                    'partner_id' => $member->partner_id,
                    'contact_id' => $member->contact_id,
                    'type' => 'NUTRITION_CAMPAIGN_STAGE',
                    'description' => 'Campaña de seguimiento: '.self::STAGES[$toStage].'.',
                    'metadata' => SqlValue::encode([
                        'nutrition_campaign_id' => $member->campaign_id,
                        'member_id' => $member->id,
                        'from_stage' => $fromStage,
                        'to_stage' => $toStage,
                        'source' => strtoupper(trim($source)) ?: 'SYSTEM',
                        'note' => $note,
                    ]),
                    'actor_id' => $changedByUserId ?? 'SYSTEM',
                    'created_at' => now(),
                ]);

                $movementCode = match ($toStage) {
                    'INVITATION_SENT' => '702',
                    'CONFIRMED' => '703',
                    default => null,
                };
                if ($movementCode !== null && $fromStage !== $toStage) {
                    $this->movements->record(
                        $movementCode,
                        (string) $member->partner_id,
                        'NUTRITION_CAMPAIGN_MEMBER',
                        (string) $member->id,
                        [
                            'campaign_id' => (string) $member->campaign_id,
                            'contact_id' => (string) $member->contact_id,
                            'from_stage' => $fromStage,
                            'to_stage' => $toStage,
                            'source' => strtoupper(trim($source)) ?: 'SYSTEM',
                        ],
                        $note,
                        'NUTRITION_CAMPAIGN',
                        (string) $member->campaign_id,
                    );
                }
            }

            $fresh = DB::table('nutrition_followup_members')
                ->where('id', $memberId)
                ->first();

            return $fresh === null ? [] : (array) $fresh;
        }, 3);
    }

    /** @return array<string,mixed> */
    private function timestampUpdates(string $stage, object $member): array
    {
        return match ($stage) {
            'INVITATION_SENT' => [
                'invited_at' => $member->invited_at ?? now(),
                'invitation_status' => 'SENT',
            ],
            'DELIVERED' => ['invitation_status' => 'DELIVERED'],
            'READ' => ['invitation_status' => 'READ'],
            'RESPONDED' => ['responded_at' => $member->responded_at ?? now()],
            'CONFIRMED' => [
                'responded_at' => $member->responded_at ?? now(),
                'confirmed_at' => $member->confirmed_at ?? now(),
            ],
            'DECLINED', 'NO_RESPONSE', 'OPTED_OUT' => [
                'responded_at' => $member->responded_at ?? now(),
            ],
            'ATTENDED' => ['attended_at' => $member->attended_at ?? now()],
            'REWEIGHED' => ['reweighed_at' => $member->reweighed_at ?? now()],
            'INTERESTED' => ['interested_at' => $member->interested_at ?? now()],
            default => [],
        };
    }
}
