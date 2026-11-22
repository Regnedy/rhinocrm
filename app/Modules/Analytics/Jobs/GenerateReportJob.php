<?php

namespace App\Modules\Analytics\Jobs;

use App\Modules\Auth\Services\TenantContextRunner;
use App\Support\Database\SqlValue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 1200;

    private const ALLOWED_TABLES = [
        'contacts', 'measurements', 'tasks', 'sales', 'financial_transactions',
        'inventory_transactions', 'whatsapp_messages', 'timeline_activities',
        'users', 'teams', 'devices',
    ];

    public function __construct(
        public readonly string $reportId,
        public readonly string $partnerId,
        public readonly string $table,
        public readonly array $columns,
    ) {}

    public function handle(TenantContextRunner $runner): void
    {
        $runner->runAsSystem($this->partnerId, function (): void {
            if (!in_array($this->table, self::ALLOWED_TABLES, true)) {
                throw new \InvalidArgumentException('Fuente no autorizada para exportación.');
            }
            $available = Schema::getColumnListing($this->table);
            $columns = array_values(array_unique(array_map('strval', $this->columns)));
            if ($columns === [] || array_diff($columns, $available) !== []) {
                throw new \InvalidArgumentException('Una o más columnas no existen en la fuente solicitada.');
            }

            DB::table('report_exports')->where('id', $this->reportId)->update([
                'status' => 'PROCESSING', 'started_at' => now(), 'last_error' => null, 'updated_at' => now(),
            ]);

            $diskName = (string) config('rhino.reports.disk', 'local');
            $relativePath = "reports/{$this->partnerId}/{$this->reportId}.csv";
            $disk = Storage::disk($diskName);
            $disk->makeDirectory("reports/{$this->partnerId}");
            $stream = fopen($disk->path($relativePath), 'wb');
            if ($stream === false) {
                throw new \RuntimeException('No se pudo crear el archivo del reporte.');
            }

            $count = 0;
            try {
                fputcsv($stream, $columns);
                $query = DB::table($this->table)->where('partner_id', $this->partnerId)->select($columns);
                foreach ($query->orderBy($available[0])->cursor() as $row) {
                    $values = [];
                    foreach ($columns as $column) {
                        $values[] = $this->scalar($row->{$column} ?? null);
                    }
                    fputcsv($stream, $values);
                    $count++;
                }
                fclose($stream);
                $stream = null;

                DB::table('report_exports')->where('id', $this->reportId)->update([
                    'status' => 'COMPLETED',
                    'file_path' => $relativePath,
                    'row_count' => $count,
                    'completed_at' => now(),
                    'last_error' => null,
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $exception) {
                DB::table('report_exports')->where('id', $this->reportId)->update([
                    'status' => 'FAILED',
                    'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                    'updated_at' => now(),
                ]);
                throw $exception;
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        });
    }

    private function scalar(mixed $value): string|int|float|null
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value)) {
            // Evita que Excel/LibreOffice interpreten datos del CRM como fórmulas.
            return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
        }
        if (is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        return SqlValue::encode($value);
    }
}
