<?php

namespace App\Modules\PersonalReports\Services;

use App\Support\Database\SqlValue;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PersonalReportPdfService
{
    public function __construct(
        private readonly NutritionRecommendationService $nutritionRecommendations,
    ) {}

    /** @return array{disk:string,path:string,absolute_path:string,filename:string,hash:string} */
    public function generate(string $reportId): array
    {
        $report = DB::table('personal_reports as pr')
            ->join('contacts as c', 'c.id', '=', 'pr.contact_id')
            ->join('campaigns as ca', 'ca.id', '=', 'pr.campaign_id')
            ->join('measurements as m', 'm.id', '=', 'pr.measurement_id')
            ->leftJoin('users as u', 'u.id', '=', 'ca.responsible_user_id')
            ->where('pr.id', $reportId)
            ->select(
                'pr.*',
                'c.name as contact_name', 'c.age', 'c.gender', 'c.height_cm', 'c.profile', 'c.whatsapp_normalized',
                'ca.name as campaign_name', 'ca.starts_at as campaign_starts_at', 'ca.venue_name', 'ca.city',
                'u.name as responsible_name',
                'm.biometrics_raw', 'm.biometrics_calculated', 'm.timestamp_local', 'm.device_id'
            )
            ->first();
        if ($report === null) {
            throw new \RuntimeException('Informe personal no encontrado.', 404);
        }

        $diskName = (string) config('rhino.personal_reports.disk', 'local');
        $directory = trim((string) config('rhino.personal_reports.directory', 'personal-reports'), '/');
        $relativePath = "{$directory}/{$report->partner_id}/{$report->campaign_id}/{$report->id}.pdf";
        $disk = Storage::disk($diskName);

        if ($report->file_path !== null && $disk->exists((string) $report->file_path)) {
            $absolute = $disk->path((string) $report->file_path);
            return [
                'disk' => $diskName,
                'path' => (string) $report->file_path,
                'absolute_path' => $absolute,
                'filename' => $this->filename((string) $report->contact_name, (string) $report->public_code),
                'hash' => hash_file('sha256', $absolute) ?: '',
            ];
        }

        $firstMeasurement = DB::table('measurements')
            ->where('partner_id', $report->partner_id)
            ->where('contact_id', $report->contact_id)
            ->orderBy('timestamp_local')
            ->first();
        $measurementCount = DB::table('measurements')
            ->where('partner_id', $report->partner_id)
            ->where('contact_id', $report->contact_id)
            ->count();
        $nutrition = DB::table('nutrition_profiles')
            ->where('partner_id', $report->partner_id)
            ->where('contact_id', $report->contact_id)
            ->first();

        $currentRaw = SqlValue::json($report->biometrics_raw);
        $currentCalculated = SqlValue::json($report->biometrics_calculated);
        $firstRaw = $firstMeasurement !== null ? SqlValue::json($firstMeasurement->biometrics_raw) : [];
        $firstCalculated = $firstMeasurement !== null ? SqlValue::json($firstMeasurement->biometrics_calculated) : [];

        $metrics = $this->metrics($currentRaw, $currentCalculated, $firstRaw, $firstCalculated);
        $nutritionPlan = $this->nutritionRecommendations->build(
            $report,
            $nutrition,
            $currentRaw,
            $currentCalculated,
        );
        $this->nutritionRecommendations->persistVersion($report, $nutritionPlan);

        $viewData = [
            'report' => $report,
            'metrics' => $metrics,
            'nutritionPlan' => $nutritionPlan,
            'measurementCount' => $measurementCount,
            'firstMeasuredAt' => $firstMeasurement?->timestamp_local,
            'generatedAt' => now(),
            'disclaimer' => (string) config('rhino.personal_reports.disclaimer'),
        ];

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('chroot', [base_path(), storage_path()]);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml(view('reports.personal-measurement', $viewData)->render(), 'UTF-8');
        $dompdf->render();
        $binary = $dompdf->output();
        if ($binary === '') {
            throw new \RuntimeException('El motor PDF no produjo contenido.');
        }

        $disk->makeDirectory(dirname($relativePath));
        $disk->put($relativePath, $binary, ['visibility' => 'private']);
        $absolutePath = $disk->path($relativePath);
        $hash = hash('sha256', $binary);

        DB::table('personal_reports')->where('id', $reportId)->update([
            'status' => 'READY',
            'storage_disk' => $diskName,
            'file_path' => $relativePath,
            'content_hash' => $hash,
            'generated_at' => now(),
            'last_error' => null,
            'updated_at' => now(),
        ]);

        return [
            'disk' => $diskName,
            'path' => $relativePath,
            'absolute_path' => $absolutePath,
            'filename' => $this->filename((string) $report->contact_name, (string) $report->public_code),
            'hash' => $hash,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function metrics(array $raw, array $calculated, array $firstRaw, array $firstCalculated): array
    {
        $definitions = [
            ['key' => 'peso_kg', 'label' => 'Peso', 'unit' => 'kg', 'current' => $raw, 'first' => $firstRaw, 'decimals' => 1],
            ['key' => 'imc', 'label' => 'IMC estimado', 'unit' => '', 'current' => $calculated, 'first' => $firstCalculated, 'decimals' => 1],
            ['key' => 'grasa_porcentaje', 'label' => 'Grasa corporal estimada', 'unit' => '%', 'current' => $calculated, 'first' => $firstCalculated, 'decimals' => 1],
            ['key' => 'masa_magra_kg', 'label' => 'Masa libre de grasa estimada', 'unit' => 'kg', 'current' => $calculated, 'first' => $firstCalculated, 'decimals' => 1],
            ['key' => 'musculo_porcentaje', 'label' => 'Porcentaje muscular estimado', 'unit' => '%', 'current' => $calculated, 'first' => $firstCalculated, 'decimals' => 1],
            ['key' => 'grasa_subcutanea_porcentaje', 'label' => 'Grasa subcutánea estimada', 'unit' => '%', 'current' => $calculated, 'first' => $firstCalculated, 'decimals' => 1],
            ['key' => 'grasa_visceral', 'label' => 'Indicador de grasa visceral', 'unit' => '', 'current' => $calculated, 'first' => $firstCalculated, 'decimals' => 1],
            ['key' => 'agua_porcentaje', 'label' => 'Agua corporal estimada', 'unit' => '%', 'current' => $calculated, 'first' => $firstCalculated, 'decimals' => 1],
            ['key' => 'musculo_esqueletico_porcentaje', 'label' => 'Músculo esquelético estimado', 'unit' => '%', 'current' => $calculated, 'first' => $firstCalculated, 'decimals' => 1],
            ['key' => 'masa_muscular_kg', 'label' => 'Masa muscular estimada', 'unit' => 'kg', 'current' => $calculated, 'first' => $firstCalculated, 'decimals' => 1],
            ['key' => 'masa_osea_kg', 'label' => 'Masa ósea estimada', 'unit' => 'kg', 'current' => $calculated, 'first' => $firstCalculated, 'decimals' => 1],
            ['key' => 'proteina_porcentaje', 'label' => 'Proteína corporal estimada', 'unit' => '%', 'current' => $calculated, 'first' => $firstCalculated, 'decimals' => 1],
            ['key' => 'bmr_kcal', 'label' => 'Metabolismo basal estimado', 'unit' => 'kcal/día', 'current' => $calculated, 'first' => $firstCalculated, 'decimals' => 0],
            ['key' => 'edad_metabolica', 'label' => 'Edad metabólica estimada', 'unit' => 'años', 'current' => $calculated, 'first' => $firstCalculated, 'decimals' => 0],
        ];

        $result = [];
        foreach ($definitions as $definition) {
            $current = $this->numeric($definition['current'][$definition['key']] ?? null);
            if ($current === null) {
                continue;
            }
            $first = $this->numeric($definition['first'][$definition['key']] ?? null);
            $result[] = [
                'label' => $definition['label'],
                'value' => number_format($current, $definition['decimals'], '.', '').($definition['unit'] !== '' ? ' '.$definition['unit'] : ''),
                'first_value' => $first !== null ? number_format($first, $definition['decimals'], '.', '').($definition['unit'] !== '' ? ' '.$definition['unit'] : '') : null,
                'change' => $first !== null ? number_format($current - $first, $definition['decimals'], '.', '') : null,
                'unit' => $definition['unit'],
            ];
        }
        return $result;
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function filename(string $name, string $code): string
    {
        $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name) ?: 'persona';
        return 'Informe-RHINO-'.trim($safeName, '-').'-'.$code.'.pdf';
    }
}
