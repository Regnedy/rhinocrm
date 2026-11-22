<?php

namespace App\Modules\Integrations\Whatsapp\Services;

use App\Modules\Movements\Services\MovementService;
use App\Modules\NutritionCampaigns\Services\NutritionCampaignStageService;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class WhatsappStatusService
{
    public function __construct(
        private readonly NutritionCampaignStageService $campaignStages,
        private readonly MovementService $movements,
    ) {}

    public function apply(string $externalId, string $status, ?string $error): void
    {
        $campaignTransition = null;
        $nutritionTransition = null;

        DB::transaction(function () use ($externalId, $status, $error, &$campaignTransition, &$nutritionTransition): void {
            $newStatus = strtoupper(trim($status));
            $message = DB::table('whatsapp_messages')->where('external_id', $externalId)->lockForUpdate()->first();
            $delivery = DB::table('report_deliveries')->where('external_id', $externalId)->lockForUpdate()->first();
            $campaignMessage = Schema::hasTable('nutrition_followup_messages')
                ? DB::table('nutrition_followup_messages')
                    ->where('external_id', $externalId)
                    ->lockForUpdate()
                    ->first()
                : null;
            if ($message === null && $delivery === null && $campaignMessage === null) {
                return;
            }

            if ($message !== null) {
                $previousStatus = (string) $message->status;
                DB::table('whatsapp_messages')->where('id', $message->id)->update([
                    'status' => $newStatus,
                    'error_message' => $error,
                    'updated_at' => now(),
                ]);

                $eventId = 'EVT_WA_'.strtoupper(substr(hash('sha256', $externalId.'|'.$newStatus), 0, 24));
                DB::table('event_outbox')->insertOrIgnore([
                    'id' => $eventId,
                    'event_type' => 'WHATSAPP_STATUS_CHANGED',
                    'event_version' => 1,
                    'partner_id' => $message->partner_id,
                    'payload' => SqlValue::encode([
                        'message_id' => $message->id,
                        'contact_id' => $message->contact_id,
                        'status' => $newStatus,
                        'previous_status' => $previousStatus,
                        'template_name' => $message->template_name,
                        'error_message' => $error,
                    ]),
                    'status' => 'PENDING',
                    'attempt_count' => 0,
                    'available_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $variables = SqlValue::json($message->variables ?? null);
                $nutritionPlanId = trim((string) ($variables['nutrition_plan_id'] ?? ''));
                if ($nutritionPlanId !== '' && Schema::hasTable('nutrition_plan_versions')) {
                    $plan = DB::table('nutrition_plan_versions')->where('id', $nutritionPlanId)->lockForUpdate()->first();
                    if ($plan !== null) {
                        $planStatus = in_array($newStatus, ['SENT', 'DELIVERED', 'READ', 'FAILED'], true) ? $newStatus : (string) $plan->delivery_status;
                        $updates = ['delivery_status' => $planStatus, 'updated_at' => now()];
                        if (in_array($newStatus, ['DELIVERED', 'READ'], true)) $updates['delivered_at'] = $plan->delivered_at ?? now();
                        DB::table('nutrition_plan_versions')->where('id', $nutritionPlanId)->update($updates);
                        $activityId = 'ACT_WA_'.strtoupper(substr(hash('sha256', $externalId.'|NUTRITION|'.$newStatus), 0, 24));
                        DB::table('timeline_activities')->insertOrIgnore([
                            'id' => $activityId,
                            'partner_id' => (string) $plan->partner_id,
                            'contact_id' => (string) $plan->contact_id,
                            'type' => 'NUTRITION_PLAN_WHATSAPP_'.$newStatus,
                            'description' => match ($newStatus) {
                                'DELIVERED' => 'WhatsApp confirmó la entrega del plan alimenticio.',
                                'READ' => 'WhatsApp confirmó la lectura del plan alimenticio.',
                                'FAILED' => 'WhatsApp informó que el envío del plan alimenticio falló.',
                                default => 'Se actualizó el estado de WhatsApp del plan alimenticio.',
                            },
                            'metadata' => SqlValue::encode(['plan_id' => $nutritionPlanId, 'external_id' => $externalId, 'error' => $error]),
                            'actor_id' => null,
                            'created_at' => now(),
                        ]);
                        if (in_array($newStatus, ['DELIVERED', 'READ', 'FAILED'], true)) {
                            $nutritionTransition = ['plan_id'=>$nutritionPlanId,'partner_id'=>(string)$plan->partner_id,'contact_id'=>(string)$plan->contact_id,'status'=>$newStatus,'external_id'=>$externalId,'error'=>$error];
                        }
                    }
                }
            }

            if ($delivery !== null) {
                $updates = [
                    'status' => in_array($newStatus, ['SENT', 'DELIVERED', 'READ', 'FAILED'], true) ? $newStatus : (string) $delivery->status,
                    'last_error' => $error,
                    'updated_at' => now(),
                ];
                if ($newStatus === 'SENT') $updates['sent_at'] = $delivery->sent_at ?? now();
                if ($newStatus === 'DELIVERED') $updates['delivered_at'] = now();
                if ($newStatus === 'READ') {
                    $updates['delivered_at'] = $delivery->delivered_at ?? now();
                    $updates['read_at'] = now();
                }
                if ($newStatus === 'FAILED') $updates['failed_at'] = now();
                DB::table('report_deliveries')->where('id', $delivery->id)->update($updates);
                $planUpdates = [
                    'delivery_status' => in_array($newStatus, ['SENT', 'DELIVERED', 'READ', 'FAILED'], true)
                        ? $newStatus
                        : (string) $delivery->status,
                    'updated_at' => now(),
                ];
                if (in_array($newStatus, ['DELIVERED', 'READ'], true)) {
                    $planUpdates['delivered_at'] = now();
                }
                DB::table('nutrition_plan_versions')
                    ->where('personal_report_id', $delivery->report_id)
                    ->update($planUpdates);
                $this->refreshBatch((string) $delivery->batch_id);
            }

            if ($campaignMessage !== null) {
                $campaignUpdates = [
                    'status' => in_array($newStatus, ['SENT', 'DELIVERED', 'READ', 'FAILED'], true)
                        ? $newStatus
                        : (string) $campaignMessage->status,
                    'last_error' => $error,
                    'updated_at' => now(),
                ];
                if ($newStatus === 'SENT') $campaignUpdates['sent_at'] = $campaignMessage->sent_at ?? now();
                if ($newStatus === 'DELIVERED') $campaignUpdates['delivered_at'] = now();
                if ($newStatus === 'READ') {
                    $campaignUpdates['delivered_at'] = $campaignMessage->delivered_at ?? now();
                    $campaignUpdates['read_at'] = now();
                }
                if ($newStatus === 'FAILED') $campaignUpdates['failed_at'] = now();

                DB::table('nutrition_followup_messages')
                    ->where('id', $campaignMessage->id)
                    ->update($campaignUpdates);

                $stage = match ($newStatus) {
                    'DELIVERED' => 'DELIVERED',
                    'READ' => 'READ',
                    default => null,
                };
                if ($stage !== null) {
                    $campaignTransition = [
                        'member_id' => (string) $campaignMessage->member_id,
                        'stage' => $stage,
                    ];
                }
            }
        }, 3);

        if (is_array($campaignTransition)) {
            $this->campaignStages->transition(
                $campaignTransition['member_id'],
                $campaignTransition['stage'],
                'WHATSAPP_STATUS',
            );
        }

        if (is_array($nutritionTransition)) {
            $movementCode = match ($nutritionTransition['status']) {'DELIVERED'=>'607','READ'=>'608','FAILED'=>'609',default=>null};
            if ($movementCode !== null && !DB::table('business_movements')
                ->where('movement_code', $movementCode)
                ->where('target_type', 'NUTRITION_PLAN')
                ->where('target_id', $nutritionTransition['plan_id'])
                ->where('reference_type', 'WHATSAPP_MESSAGE')
                ->where('reference_id', $nutritionTransition['external_id'])
                ->exists()) {
                $this->movements->record($movementCode,$nutritionTransition['partner_id'],'NUTRITION_PLAN',$nutritionTransition['plan_id'],[
                    'contact_id'=>$nutritionTransition['contact_id'],'external_id'=>$nutritionTransition['external_id'],'error'=>$nutritionTransition['error'],
                ], null, 'WHATSAPP_MESSAGE', $nutritionTransition['external_id']);
            }
        }
    }

    private function refreshBatch(string $batchId): void
    {
        $batch = DB::table('event_report_batches')->where('id', $batchId)->first();
        if ($batch === null) return;
        $counts = DB::table('report_deliveries')
            ->where('batch_id', $batchId)
            ->selectRaw("SUM(CASE WHEN status = 'QUEUED' THEN 1 ELSE 0 END) as queued_count")
            ->selectRaw("SUM(CASE WHEN status = 'PROCESSING' THEN 1 ELSE 0 END) as processing_count")
            ->selectRaw("SUM(CASE WHEN status IN ('SENT','DELIVERED','READ') THEN 1 ELSE 0 END) as sent_count")
            ->selectRaw("SUM(CASE WHEN status IN ('DELIVERED','READ') THEN 1 ELSE 0 END) as delivered_count")
            ->selectRaw("SUM(CASE WHEN status = 'READ' THEN 1 ELSE 0 END) as read_count")
            ->selectRaw("SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) as failed_count")
            ->selectRaw("SUM(CASE WHEN status = 'SKIPPED' THEN 1 ELSE 0 END) as skipped_count")
            ->first();
        $pending = (int) ($counts->queued_count ?? 0) + (int) ($counts->processing_count ?? 0);
        DB::table('event_report_batches')->where('id', $batchId)->update([
            'status' => $pending > 0 ? 'PROCESSING' : 'COMPLETED',
            'queued_count' => (int) ($counts->queued_count ?? 0),
            'sent_count' => (int) ($counts->sent_count ?? 0),
            'delivered_count' => (int) ($counts->delivered_count ?? 0),
            'read_count' => (int) ($counts->read_count ?? 0),
            'failed_count' => (int) ($counts->failed_count ?? 0),
            'skipped_count' => (int) ($counts->skipped_count ?? 0),
            'completed_at' => $pending > 0 ? null : now(),
            'updated_at' => now(),
        ]);
    }
}
