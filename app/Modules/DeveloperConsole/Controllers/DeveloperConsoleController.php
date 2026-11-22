<?php

namespace App\Modules\DeveloperConsole\Controllers;

use App\Modules\DeveloperConsole\Services\AiDiagnosticService;
use App\Modules\DeveloperConsole\Services\CodeContextService;
use App\Modules\DeveloperConsole\Services\LogReaderService;
use App\Modules\DeveloperConsole\Services\MaintenanceAuditService;
use App\Modules\DeveloperConsole\Services\SafeCommandService;
use App\Modules\DeveloperConsole\Services\SystemHealthService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeveloperConsoleController
{
    use ApiResponse;

    public function status(SystemHealthService $health): JsonResponse
    {
        return $this->successResponse($health->snapshot());
    }

    public function logFiles(LogReaderService $logs): JsonResponse
    {
        return $this->successResponse($logs->files());
    }

    public function logs(Request $request, LogReaderService $logs): JsonResponse
    {
        $data = $request->validate([
            'file' => ['nullable', 'string', 'max:255'],
            'lines' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'level' => ['nullable', 'string', 'in:DEBUG,INFO,NOTICE,WARNING,ERROR,CRITICAL,ALERT,EMERGENCY,UNKNOWN'],
            'search' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->successResponse($logs->read(
            (string) ($data['file'] ?? 'laravel'),
            (int) ($data['lines'] ?? 250),
            isset($data['level']) ? (string) $data['level'] : null,
            isset($data['search']) ? (string) $data['search'] : null,
        ));
    }

    public function commands(SafeCommandService $commands): JsonResponse
    {
        return $this->successResponse($commands->catalog());
    }

    public function executeCommand(string $action, Request $request, SafeCommandService $commands): JsonResponse
    {
        $data = $request->validate([
            'confirmation_phrase' => ['nullable', 'string', 'max:120'],
            'reauth_password' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->successResponse($commands->execute(
            $action,
            isset($data['confirmation_phrase']) ? (string) $data['confirmation_phrase'] : null,
            isset($data['reauth_password']) ? (string) $data['reauth_password'] : null,
        ), 'Acción de mantenimiento completada.');
    }

    public function sourceFiles(Request $request, CodeContextService $code): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        return $this->successResponse($code->files(
            isset($data['search']) ? (string) $data['search'] : null,
            (int) ($data['limit'] ?? 150),
        ));
    }

    public function source(Request $request, CodeContextService $code): JsonResponse
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:1000']]);
        return $this->successResponse($code->read((string) $data['path']));
    }

    public function analyze(Request $request, AiDiagnosticService $assistant): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'min:10', 'max:8000'],
            'log_file' => ['nullable', 'string', 'max:255'],
            'log_lines' => ['nullable', 'integer', 'min:1', 'max:500'],
            'source_paths' => ['nullable', 'array', 'max:12'],
            'source_paths.*' => ['string', 'max:1000'],
        ]);

        return $this->successResponse($assistant->analyze(
            (string) $data['question'],
            isset($data['log_file']) ? (string) $data['log_file'] : null,
            (int) ($data['log_lines'] ?? 120),
            array_values(array_map('strval', (array) ($data['source_paths'] ?? []))),
        ), 'Diagnóstico generado para revisión humana.');
    }

    public function aiHistory(Request $request, AiDiagnosticService $assistant): JsonResponse
    {
        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:200']]);
        return $this->successResponse($assistant->history((int) ($data['limit'] ?? 50)));
    }

    public function actions(Request $request, MaintenanceAuditService $audit): JsonResponse
    {
        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:500']]);
        return $this->successResponse($audit->recent((int) ($data['limit'] ?? 100)));
    }
}
