<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * RHINO 2.7.0: la generación de dieta es una acción colaborativa, nunca una
 * transferencia de propiedad. Capturador y responsable se administran aparte.
 */
final class CampaignDietOwnershipService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly CampaignActivityService $activity,
    ) {}

    /** @return array{id:string,claimed:bool}|null */
    public function claimForGeneration(string $contactId, string $measurementId): ?array
    {
        if (!DB::getSchemaBuilder()->hasColumn('campaign_contacts', 'diet_claimed_by_user_id')) return null;
        return DB::transaction(function () use ($contactId, $measurementId): ?array {
            $participant = $this->participant($contactId, $measurementId, true);
            if ($participant === null) return null;
            $campaign = DB::table('campaigns')->where('id', $participant->campaign_id)->first();
            if ($campaign === null || in_array((string) $campaign->status, ['CLOSED', 'CANCELLED'], true)) {
                throw new \RuntimeException('El evento ya no permite generar dietas.', 409);
            }
            $claimedBy = $participant->diet_claimed_by_user_id !== null ? (string) $participant->diet_claimed_by_user_id : null;
            $claimIsFresh = $participant->diet_claimed_at !== null
                && Carbon::parse((string) $participant->diet_claimed_at)->greaterThan(now()->subMinutes(10));
            if ($claimedBy !== null && $claimedBy !== $this->context->userId && $claimIsFresh) {
                $name = DB::table('users')->where('id', $claimedBy)->value('name') ?: 'otro colaborador';
                throw new \RuntimeException("{$name} está generando la dieta de este prospecto en este momento.", 409);
            }
            $userPartner = DB::table('users')->where('id', $this->context->userId)->whereNull('deleted_at')->value('partner_id');
            if ($userPartner === null) throw new \RuntimeException('Tu cuenta no tiene una organización válida.', 422);
            $alreadyMine = $claimedBy === $this->context->userId && $claimIsFresh;
            DB::table('campaign_contacts')->where('id', $participant->id)->update([
                'diet_claimed_by_user_id' => $this->context->userId,
                'diet_claimed_by_partner_id' => (string) $userPartner,
                'diet_claimed_at' => now(),
                'attention_status' => $participant->nutrition_plan_id === null ? 'DIET_GENERATING' : $participant->attention_status,
                'updated_at' => now(),
            ]);
            return ['id' => (string) $participant->id, 'claimed' => !$alreadyMine];
        }, 3);
    }

    /** @param array{id:string,claimed:bool}|null $claim */
    public function releaseGenerationClaim(?array $claim): void
    {
        if ($claim === null) return;
        DB::table('campaign_contacts')->where('id', $claim['id'])
            ->where('diet_claimed_by_user_id', $this->context->userId)
            ->whereNull('nutrition_plan_id')->update([
                'diet_claimed_by_user_id' => null, 'diet_claimed_by_partner_id' => null,
                'diet_claimed_at' => null, 'attention_status' => 'PENDING_DIET', 'updated_at' => now(),
            ]);
    }

    public function assignFromGeneratedPlan(string $contactId, string $measurementId, string $planId): void
    {
        if (!DB::getSchemaBuilder()->hasColumn('campaign_contacts', 'diet_generated_by_user_id')) return;
        $activity = DB::transaction(function () use ($contactId, $measurementId, $planId): ?array {
            $participant = $this->participant($contactId, $measurementId, true);
            if ($participant === null) return null;
            $campaign = DB::table('campaigns')->where('id', $participant->campaign_id)->first();
            if ($campaign === null || in_array((string) $campaign->status, ['CLOSED', 'CANCELLED'], true)) {
                throw new \RuntimeException('El evento se cerró antes de completar la dieta.', 409);
            }
            if ($participant->diet_claimed_by_user_id !== null && (string) $participant->diet_claimed_by_user_id !== $this->context->userId) {
                throw new \RuntimeException('La reserva para generar esta dieta pertenece a otro colaborador.', 409);
            }
            $partnerId = DB::table('users')->where('id', $this->context->userId)->value('partner_id');
            DB::table('campaign_contacts')->where('id', $participant->id)->update([
                'diet_generated_by_user_id' => $this->context->userId,
                'diet_generated_by_partner_id' => $partnerId,
                'nutrition_plan_id' => $planId,
                'nutrition_generated_at' => now(),
                'diet_claimed_by_user_id' => null,
                'diet_claimed_by_partner_id' => null,
                'diet_claimed_at' => null,
                'attention_status' => 'DIET_GENERATED',
                'updated_at' => now(),
            ]);
            return ['campaign_id' => (string) $campaign->id, 'campaign_partner_id' => (string) $campaign->partner_id];
        }, 3);
        if ($activity !== null) {
            $this->activity->record($activity['campaign_id'], $activity['campaign_partner_id'], 'DIET_GENERATED', $contactId, [
                'plan_id' => $planId,
                'generated_by_user_id' => $this->context->userId,
            ]);
        }
    }

    private function participant(string $contactId, string $measurementId, bool $lock = false): ?object
    {
        $campaignId = DB::table('measurements')->where('id', $measurementId)->value('campaign_id');
        if ($campaignId === null) return null;
        $query = DB::table('campaign_contacts')->where('campaign_id', (string) $campaignId)->where('contact_id', $contactId);
        if ($lock) $query->lockForUpdate();
        return $query->first();
    }
}
