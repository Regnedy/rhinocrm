<?php

namespace App\Modules\DeveloperConsole\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class AiDiagnosticService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly CodeContextService $code,
        private readonly LogReaderService $logs,
        private readonly SensitiveDataRedactor $redactor,
        private readonly MaintenanceAuditService $audit,
    ) {}

    /** @return array<string,mixed> */
    public function analyze(string $question, ?string $logFile, int $logLines, array $sourcePaths): array
    {
        $this->assertConfigured();
        $question = trim($question);
        if ($question === '') {
            throw new \InvalidArgumentException('La pregunta es obligatoria.');
        }

        $sources = $sourcePaths !== [] ? $this->code->readMany($sourcePaths) : [];
        $logContext = $logFile !== null && $logFile !== '' ? $this->logs->context($logFile, $logLines) : '';
        $manifest = [
            'log_file' => $logFile,
            'log_lines' => $logFile !== null ? $logLines : 0,
            'source_paths' => array_column($sources, 'path'),
            'redaction_enabled' => true,
        ];

        $id = 'AID_'.strtoupper((string) Str::ulid());
        $model = (string) config('developer_console.ai.model');
        DB::table('ai_diagnostic_requests')->insert([
            'id' => $id,
            'actor_user_id' => $this->context->userId,
            'status' => 'RUNNING',
            'model' => $model,
            'question' => $this->redactor->redact(mb_substr($question, 0, 8000)),
            'context_manifest' => SqlValue::encode($manifest),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $auditStarted = hrtime(true);
        $auditId = $this->audit->start('AI_ANALYSIS', 'developer_console.analyze', $manifest);

        try {
            $input = $this->buildInput($question, $logContext, $sources);
            $response = Http::withToken((string) config('developer_console.ai.api_key'))
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('developer_console.ai.timeout_seconds', 60))
                ->post((string) config('developer_console.ai.endpoint'), [
                    'model' => $model,
                    'store' => false,
                    'instructions' => $this->instructions(),
                    'input' => $input,
                    'max_output_tokens' => (int) config('developer_console.ai.max_output_tokens', 2400),
                ]);

            if (!$response->successful()) {
                $message = (string) ($response->json('error.message') ?? 'La API de asistencia devolvió un error HTTP '.$response->status().'.');
                throw new \RuntimeException($this->redactor->redact($message, false), 502);
            }

            $payload = $response->json();
            if (!is_array($payload)) {
                throw new \RuntimeException('La respuesta del asistente no contiene JSON válido.', 502);
            }
            $text = $this->extractText($payload);
            if ($text === '') {
                throw new \RuntimeException('El asistente no devolvió un diagnóstico textual.', 502);
            }
            $text = $this->redactor->redact($text, false);
            $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : null;

            DB::table('ai_diagnostic_requests')->where('id', $id)->update([
                'status' => 'COMPLETED',
                'provider_request_id' => isset($payload['id']) ? (string) $payload['id'] : null,
                'response_text' => $text,
                'usage' => $usage !== null ? SqlValue::encode($usage) : null,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->success($auditId, $text, $auditStarted);

            return [
                'id' => $id,
                'model' => $model,
                'response' => $text,
                'usage' => $usage,
                'context_manifest' => $manifest,
                'completed_at' => now()->toAtomString(),
            ];
        } catch (\Throwable $exception) {
            DB::table('ai_diagnostic_requests')->where('id', $id)->update([
                'status' => 'FAILED',
                'error_message' => $this->redactor->redact(mb_substr($exception->getMessage(), 0, 2000), false),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->failure($auditId, $exception, $auditStarted);
            throw $exception;
        }
    }

    /** @return array<int,object> */
    public function history(int $limit = 50): array
    {
        return DB::table('ai_diagnostic_requests')
            ->where('actor_user_id', $this->context->userId)
            ->select('id', 'status', 'model', 'question', 'context_manifest', 'response_text', 'error_message', 'completed_at', 'created_at')
            ->orderByDesc('created_at')
            ->limit(max(1, min(200, $limit)))
            ->get()
            ->all();
    }

    private function assertConfigured(): void
    {
        if (!(bool) config('developer_console.ai.enabled')) {
            throw new \RuntimeException('El asistente IA está deshabilitado. Configure RHINO_DEV_CONSOLE_AI_ENABLED=true.', 503);
        }
        if (trim((string) config('developer_console.ai.api_key')) === '' || trim((string) config('developer_console.ai.model')) === '') {
            throw new \RuntimeException('Faltan OPENAI_API_KEY u OPENAI_MODEL en el servidor.', 503);
        }
    }

    /** @param array<int,array{path:string,content:string,size:int,truncated:bool}> $sources */
    private function buildInput(string $question, string $logContext, array $sources): string
    {
        $parts = ["SOLICITUD DEL ADMINISTRADOR:\n".$this->redactor->redact($question)];
        if ($logContext !== '') {
            $parts[] = "LOG DEPURADO:\n```text\n".$this->redactor->redact($logContext)."\n```";
        }
        foreach ($sources as $source) {
            $language = str_ends_with($source['path'], '.php') ? 'php' : 'text';
            $parts[] = "ARCHIVO AUTORIZADO: {$source['path']}\n```{$language}\n{$source['content']}\n```";
        }

        $joined = implode("\n\n", $parts);
        $limit = (int) config('developer_console.ai.max_context_chars', 90000);
        if (mb_strlen($joined) > $limit) {
            $joined = mb_substr($joined, 0, $limit)."\n\n[CONTEXTO TRUNCADO POR EL SERVIDOR]";
        }
        return $joined;
    }

    private function instructions(): string
    {
        return <<<'TEXT'
Eres el asistente de mantenimiento de Rhino CRM, un backend Laravel multitenant con MySQL para hosting compartido.
Analiza únicamente la información proporcionada. No afirmes haber ejecutado comandos ni haber leído archivos no incluidos.
Nunca solicites ni reproduzcas contraseñas, tokens, claves API, cookies, credenciales de base de datos o datos personales completos.
No propongas migrate:fresh, migrate:reset, migrate:rollback, db:wipe, DROP TABLE, DROP DATABASE, eliminación masiva ni una shell web.
Toda modificación de base de datos debe plantearse como migración aditiva, compatible y con respaldo previo.
No des instrucciones para aplicar cambios automáticamente en producción. La aprobación humana es obligatoria.
Responde en español con estas secciones: Diagnóstico, Evidencia, Riesgo, Propuesta, Archivos sugeridos, Validación y Reversión.
Cuando falte información, indícalo claramente. Los fragmentos de código deben ser mínimos y revisables.
TEXT;
    }

    /** @param array<string,mixed> $payload */
    private function extractText(array $payload): string
    {
        if (isset($payload['output_text']) && is_string($payload['output_text'])) {
            return trim($payload['output_text']);
        }

        $chunks = [];
        foreach ((array) ($payload['output'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ((array) ($item['content'] ?? []) as $content) {
                if (is_array($content) && ($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    $chunks[] = $content['text'];
                }
            }
        }
        return trim(implode("\n", $chunks));
    }
}
