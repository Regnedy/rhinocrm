<?php

namespace App\Modules\NutritionEngine\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Campaigns\Services\CampaignAccessService;
use App\Modules\Movements\Services\MovementService;
use App\Modules\NutritionEngine\Support\NutritionDocumentTemplate;
use App\Modules\NutritionEngine\Support\QuickNutritionMeasurementSnapshot;
use App\Modules\NutritionEngine\Support\QuickNutritionRecommendations;
use App\Support\Database\SqlValue;
use App\Support\Database\TeamAccessScope;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class NutritionPlanPdfService
{
    private const STANDARD_LAYOUT_VERSION = 'reference-v2';
    private const QUICK_LAYOUT_VERSION = 'fitdoctor8-quick-v1';

    public function __construct(
        private readonly UserContextDTO $context,
        private readonly MovementService $movements,
        private readonly CampaignAccessService $campaignAccess,
    ) {}

    /**
     * @return array{disk:string,path:string,absolute_path:string,filename:string,hash:string,contact_name:string,phone:string}
     */
    public function generate(string $planId): array
    {
        $row = $this->authorizedPlan($planId);
        $layoutVersion = $this->layoutVersion($row);
        $diskName = (string) config('rhino.nutrition_engine.pdf_disk', 'local');
        $directory = trim((string) config('rhino.nutrition_engine.pdf_directory', 'nutrition-plans'), '/');
        $relativePath = "{$directory}/{$layoutVersion}/{$row->partner_id}/{$row->contact_id}/{$row->id}.pdf";
        $disk = Storage::disk($diskName);
        $filename = $this->filename((string) $row->contact_name, (string) $row->id, $this->isQuick($row));

        if ($row->pdf_path !== null
            && str_contains((string) $row->pdf_path, '/'.$layoutVersion.'/')
            && $disk->exists((string) $row->pdf_path)) {
            $path = (string) $row->pdf_path;
            $absolute = $disk->path($path);
            return [
                'disk' => $diskName,
                'path' => $path,
                'absolute_path' => $absolute,
                'filename' => $filename,
                'hash' => hash_file('sha256', $absolute) ?: (string) ($row->pdf_hash ?? ''),
                'contact_name' => (string) $row->contact_name,
                'phone' => (string) $row->whatsapp_normalized,
            ];
        }

        $render = $this->renderData($row);
        $options = $this->dompdfOptions();
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($render['html'], 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();
        $bytes = $dompdf->output();
        if (!is_string($bytes) || $bytes === '') {
            throw new \RuntimeException('No fue posible generar el PDF del plan alimentario.');
        }

        $disk->put($relativePath, $bytes);
        $absolute = $disk->path($relativePath);
        $hash = hash('sha256', $bytes);

        DB::transaction(function () use ($row, $relativePath, $diskName, $hash): void {
            DB::table('nutrition_plan_versions')->where('id', $row->id)->update([
                'pdf_disk' => $diskName,
                'pdf_path' => $relativePath,
                'pdf_hash' => $hash,
                'pdf_generated_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('timeline_activities')->insert([
                'id' => 'ACT_'.strtoupper((string) Str::ulid()),
                'partner_id' => (string) $row->partner_id,
                'contact_id' => (string) $row->contact_id,
                'type' => 'NUTRITION_PLAN_PDF_GENERATED',
                'description' => 'Se generó el PDF del plan alimentario '.(string) $row->id.'.',
                'metadata' => SqlValue::encode([
                    'plan_id' => (string) $row->id,
                    'measurement_id' => (string) $row->measurement_id,
                    'pdf_hash' => $hash,
                    'layout' => $this->layoutVersion($row),
                ]),
                'actor_id' => $this->context->userId,
                'created_at' => now(),
            ]);
        }, 3);

        if (!DB::table('business_movements')
            ->where('movement_code', '604')
            ->where('target_type', 'NUTRITION_PLAN')
            ->where('target_id', (string) $row->id)
            ->exists()) {
            $this->movements->record(
                '604',
                (string) $row->partner_id,
                'NUTRITION_PLAN',
                (string) $row->id,
                [
                    'measurement_id' => (string) $row->measurement_id,
                    'pdf_hash' => $hash,
                    'filename' => $filename,
                    'layout' => $layoutVersion,
                ],
            );
        }

        return [
            'disk' => $diskName,
            'path' => $relativePath,
            'absolute_path' => $absolute,
            'filename' => $filename,
            'hash' => $hash,
            'contact_name' => (string) $row->contact_name,
            'phone' => (string) $row->whatsapp_normalized,
        ];
    }

    /** @return array{row:object,html:string,layout_version:string,filename:string} */
    public function renderHtml(string $planId): array
    {
        $row = $this->authorizedPlan($planId);
        $render = $this->renderData($row);
        return [
            'row' => $row,
            'html' => $render['html'],
            'layout_version' => $this->layoutVersion($row),
            'filename' => $this->filename((string) $row->contact_name, (string) $row->id, $this->isQuick($row)),
        ];
    }

    public function authorizedPlan(string $planId): object
    {
        $partnerId = $this->context->partnerId;

        $row = DB::table('nutrition_plan_versions as np')
            ->join('contacts as c', 'c.id', '=', 'np.contact_id')
            ->leftJoin('partners as p', 'p.id', '=', 'np.partner_id')
            ->leftJoin('measurements as m', 'm.id', '=', 'np.measurement_id')
            ->where('np.id', $planId)
            ->whereNull('c.deleted_at')
            ->select(
                'np.*',
                'c.name as contact_name',
                'c.public_code',
                'c.whatsapp_normalized',
                'c.age',
                'c.gender',
                'c.height_cm',
                'c.assigned_advisor_id',
                'c.origin_team_id',
                'p.business_name as partner_name',
                'p.country_dial_code',
                'm.campaign_id as measurement_campaign_id'
            )
            ->first();

        if ($row === null) {
            throw new \RuntimeException('Plan alimentario no encontrado.', 404);
        }

        $samePartner = $partnerId !== null && (string) $row->partner_id === $partnerId;
        $campaignScopedAccess = $this->canAccessQuickCampaignPlan($row);
        if (!$this->context->isSuperAdmin() && !$samePartner && !$campaignScopedAccess) {
            // Deliberately use 404 so a foreign plan cannot be enumerated across tenants.
            throw new \RuntimeException('Plan alimentario no encontrado.', 404);
        }

        // Active event collaborators may consume only the quick plan tied to that event measurement.
        // Standard nutrition plans and unrelated contacts still follow ordinary tenant/team ownership.
        if (!$campaignScopedAccess && !$this->context->isSuperAdmin()) {
            if ($this->context->role === 'ADVISOR' && (string) ($row->assigned_advisor_id ?? '') !== $this->context->userId) {
                throw new \RuntimeException('Plan fuera de tu responsabilidad.', 403);
            }
            if ($this->context->role === 'TEAM_LEADER' && !(new TeamAccessScope($this->context))->canAccessContact($row)) {
                throw new \RuntimeException('Plan fuera de tus equipos.', 403);
            }
        }

        return $row;
    }

    private function canAccessQuickCampaignPlan(object $row): bool
    {
        if (!$this->isQuick($row)) {
            return false;
        }

        $campaignId = trim((string) ($row->measurement_campaign_id ?? ''));
        if ($campaignId === '') {
            return false;
        }

        try {
            $this->campaignAccess->campaign($campaignId);
            return true;
        } catch (\RuntimeException $e) {
            if (in_array((int) $e->getCode(), [403, 404], true)) {
                return false;
            }
            throw $e;
        }
    }

    /** @return array{html:string} */
    private function renderData(object $row): array
    {
        $snapshot = SqlValue::json($row->plan_snapshot ?? null);
        $profile = SqlValue::json($row->profile_snapshot ?? null);
        $document = NutritionDocumentTemplate::normalize($snapshot['document_template'] ?? []);
        $items = DB::table('nutrition_plan_items')
            ->where('plan_id', $row->id)
            ->orderBy('day_index')
            ->orderByRaw("FIELD(meal_slot,'BREAKFAST','SNACK_AM','LUNCH','SNACK_PM','DINNER')")
            ->get()
            ->map(fn (object $item): array => [
                'day_index' => (int) $item->day_index,
                'day_label' => (string) $item->day_label,
                'meal_slot' => (string) $item->meal_slot,
                'pill_id' => (string) $item->pill_id,
                'serving_multiplier' => (float) $item->serving_multiplier,
                'snapshot' => SqlValue::json($item->item_snapshot ?? null),
            ])
            ->all();

        $measurement = DB::table('measurements')
            ->where('id', $row->measurement_id)
            ->where('partner_id', $row->partner_id)
            ->where('contact_id', $row->contact_id)
            ->first();
        $raw = $measurement !== null ? SqlValue::json($measurement->biometrics_raw ?? null) : [];
        $calculated = $measurement !== null ? SqlValue::json($measurement->biometrics_calculated ?? null) : [];
        $metrics = QuickNutritionMeasurementSnapshot::fromArrays($raw, $calculated, [
            'age' => $row->age,
            'gender' => $row->gender,
            'height_cm' => $row->height_cm,
        ]);

        $previous = DB::table('measurements')
            ->where('partner_id', $row->partner_id)
            ->where('contact_id', $row->contact_id)
            ->when($measurement?->timestamp_local, fn ($query, $date) => $query->where('timestamp_local', '<', $date))
            ->orderByDesc('timestamp_local')
            ->first();
        $previousRaw = $previous !== null ? SqlValue::json($previous->biometrics_raw ?? null) : [];
        $previousCalculated = $previous !== null ? SqlValue::json($previous->biometrics_calculated ?? null) : [];
        $previousMetrics = QuickNutritionMeasurementSnapshot::fromArrays($previousRaw, $previousCalculated, [
            'age' => $row->age,
            'gender' => $row->gender,
            'height_cm' => $row->height_cm,
        ]);

        $recipes = [];
        foreach ($items as $item) {
            $pillId = (string) $item['pill_id'];
            if (isset($recipes[$pillId])) continue;
            $recipe = $item['snapshot'];
            $recipe['meal_slot'] = $item['meal_slot'];
            $recipe['serving_multiplier'] = $item['serving_multiplier'];
            $recipes[$pillId] = $recipe;
        }

        $quickRecommendations = is_array($snapshot['quick_recommendations'] ?? null)
            ? $snapshot['quick_recommendations']
            : QuickNutritionRecommendations::fromMetrics($metrics);
        $view = $this->isQuick($row) || (bool) ($snapshot['quick_plan'] ?? false)
            ? 'reports.nutrition-quick-plan'
            : 'reports.nutrition-plan';

        $html = view($view, [
            'plan' => $row,
            'snapshot' => $snapshot,
            'profile' => $profile,
            'items' => $items,
            'recipes' => array_values($recipes),
            'metrics' => $metrics,
            'previousMetrics' => $previousMetrics,
            'measurement' => $measurement,
            'previousMeasurement' => $previous,
            'document' => $document,
            'quickRecommendations' => $quickRecommendations,
            'disclaimer' => (string) config('rhino.personal_reports.disclaimer'),
        ])->render();

        return ['html' => $html];
    }

    private function dompdfOptions(): Options
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        return $options;
    }

    private function isQuick(object $row): bool
    {
        return in_array(strtoupper((string) ($row->source_type ?? '')), ['KIOSK_QUICK', 'QUICK_MEASUREMENT'], true);
    }

    private function layoutVersion(object $row): string
    {
        return $this->isQuick($row) ? self::QUICK_LAYOUT_VERSION : self::STANDARD_LAYOUT_VERSION;
    }

    private function filename(string $contactName, string $planId, bool $quick): string
    {
        $name = Str::slug($contactName);
        if ($name === '') $name = 'contacto';
        $prefix = $quick ? 'Plan-rapido-RHINO' : 'Plan-alimentario-RHINO';
        return $prefix.'-'.$name.'-'.substr($planId, -8).'.pdf';
    }
}
