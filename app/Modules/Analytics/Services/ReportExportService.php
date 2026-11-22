<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Jobs\GenerateReportJob;
use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportExportService
{
    public function __construct(private readonly UserContextDTO $context) {}

    public function request(string $table, array $columns): string
    {
        $partnerId = $this->context->partnerId ?? throw new \RuntimeException('Contexto sin partner.', 403);
        $reportId = 'RPT_'.strtoupper((string) Str::ulid());
        DB::table('report_exports')->insert([
            'id' => $reportId,
            'partner_id' => $partnerId,
            'requested_by' => $this->context->userId,
            'source_table' => $table,
            'columns' => SqlValue::encode(array_values($columns)),
            'status' => 'PENDING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        GenerateReportJob::dispatch($reportId, $partnerId, $table, array_values($columns));
        return $reportId;
    }

    public function status(string $reportId): array
    {
        $row = $this->findAuthorized($reportId);
        return [
            'id' => $reportId,
            'status' => (string) $row->status,
            'collection' => (string) $row->source_table,
            'columns' => SqlValue::json($row->columns),
            'row_count' => $row->row_count !== null ? (int) $row->row_count : null,
            'download_url' => (string) $row->status === 'COMPLETED' ? url("/api/v1/reports/{$reportId}/download") : null,
            'last_error' => $row->last_error,
            'created_at' => $row->created_at,
            'completed_at' => $row->completed_at,
        ];
    }

    public function download(string $reportId): StreamedResponse
    {
        $row = $this->findAuthorized($reportId);
        if ((string) $row->status !== 'COMPLETED' || empty($row->file_path)) {
            throw new \RuntimeException('El reporte todavía no está disponible.', 409);
        }
        $disk = Storage::disk((string) config('rhino.reports.disk', 'local'));
        if (!$disk->exists((string) $row->file_path)) {
            throw new \RuntimeException('El archivo del reporte no existe.', 404);
        }
        return $disk->download((string) $row->file_path, $reportId.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function findAuthorized(string $reportId): object
    {
        $row = DB::table('report_exports')->where('id', $reportId)->first();
        if ($row === null) {
            throw new \RuntimeException('Reporte no encontrado.', 404);
        }
        if (!$this->context->isSuperAdmin() && (string) $row->partner_id !== $this->context->partnerId) {
            throw new TenantViolationException($this->context->userId, (string) $row->partner_id);
        }
        return $row;
    }
}
