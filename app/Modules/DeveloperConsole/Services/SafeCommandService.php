<?php

namespace App\Modules\DeveloperConsole\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class SafeCommandService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly Request $request,
        private readonly MaintenanceAuditService $audit,
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function catalog(): array
    {
        return array_values(array_map(static fn (array $action): array => [
            'id' => $action['id'],
            'label' => $action['label'],
            'description' => $action['description'],
            'category' => $action['category'],
            'mutates_state' => $action['mutates_state'],
            'confirmation_phrase' => $action['confirmation_phrase'],
        ], $this->actions()));
    }

    /** @return array<string,mixed> */
    public function execute(string $actionId, ?string $confirmationPhrase, ?string $reauthPassword): array
    {
        $actions = $this->actions();
        if (!isset($actions[$actionId])) {
            throw new \InvalidArgumentException('Acción de mantenimiento no autorizada.');
        }

        $action = $actions[$actionId];
        if ($action['mutates_state']) {
            if (!hash_equals((string) $action['confirmation_phrase'], trim((string) $confirmationPhrase))) {
                throw new \RuntimeException('La frase de confirmación no coincide.', 422);
            }
            $this->verifyReauthentication($reauthPassword);
        }

        $startedAt = hrtime(true);
        $auditId = $this->audit->start('COMMAND', $actionId, [
            'artisan_command' => $action['command'],
            'mutates_state' => $action['mutates_state'],
        ]);

        try {
            $exitCode = Artisan::call($action['command'], $action['arguments']);
            $output = $this->truncate($this->redactor->redact(Artisan::output(), false));
            if ($exitCode !== 0) {
                throw new \RuntimeException("El comando finalizó con código {$exitCode}.\n{$output}", 500);
            }
            $this->audit->success($auditId, $output !== '' ? $output : 'Acción completada sin salida.', $startedAt);

            return [
                'action_id' => $actionId,
                'exit_code' => $exitCode,
                'output' => $output,
                'completed_at' => now()->toAtomString(),
            ];
        } catch (\Throwable $exception) {
            $this->audit->failure($auditId, $exception, $startedAt);
            throw $exception;
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function actions(): array
    {
        $items = [
            [
                'id' => 'system.about', 'label' => 'Información de Laravel', 'category' => 'Diagnóstico',
                'description' => 'Muestra versiones, controladores y configuración general sin revelar secretos.',
                'command' => 'about', 'arguments' => ['--no-ansi' => true], 'mutates_state' => false, 'confirmation_phrase' => null,
            ],
            [
                'id' => 'database.migration_status', 'label' => 'Estado de migraciones', 'category' => 'Base de datos',
                'description' => 'Consulta qué migraciones fueron ejecutadas y cuáles están pendientes. No aplica cambios.',
                'command' => 'migrate:status', 'arguments' => ['--no-ansi' => true], 'mutates_state' => false, 'confirmation_phrase' => null,
            ],
            [
                'id' => 'routes.list', 'label' => 'Listar rutas', 'category' => 'Diagnóstico',
                'description' => 'Muestra las rutas registradas por la aplicación.',
                'command' => 'route:list', 'arguments' => ['--except-vendor' => true, '--no-ansi' => true], 'mutates_state' => false, 'confirmation_phrase' => null,
            ],
            [
                'id' => 'scheduler.list', 'label' => 'Listar tareas programadas', 'category' => 'Diagnóstico',
                'description' => 'Muestra las tareas que debería ejecutar el cron.',
                'command' => 'schedule:list', 'arguments' => ['--no-ansi' => true], 'mutates_state' => false, 'confirmation_phrase' => null,
            ],
            [
                'id' => 'queue.failed', 'label' => 'Ver trabajos fallidos', 'category' => 'Colas',
                'description' => 'Lista los trabajos fallidos sin reintentarlos.',
                'command' => 'queue:failed', 'arguments' => ['--no-ansi' => true], 'mutates_state' => false, 'confirmation_phrase' => null,
            ],
            [
                'id' => 'cache.clear', 'label' => 'Limpiar caché de aplicación', 'category' => 'Caché',
                'description' => 'Limpia el caché de Laravel. No elimina datos de negocio.',
                'command' => 'cache:clear', 'arguments' => ['--no-ansi' => true], 'mutates_state' => true, 'confirmation_phrase' => 'LIMPIAR CACHE',
            ],
            [
                'id' => 'optimize.clear', 'label' => 'Limpiar cachés compilados', 'category' => 'Caché',
                'description' => 'Limpia configuración, rutas, vistas y eventos compilados.',
                'command' => 'optimize:clear', 'arguments' => ['--no-ansi' => true], 'mutates_state' => true, 'confirmation_phrase' => 'LIMPIAR OPTIMIZACION',
            ],
            [
                'id' => 'outbox.process', 'label' => 'Procesar outbox', 'category' => 'Colas',
                'description' => 'Procesa eventos pendientes del transactional outbox con sus controles de idempotencia.',
                'command' => 'outbox:process', 'arguments' => ['--no-ansi' => true], 'mutates_state' => true, 'confirmation_phrase' => 'PROCESAR OUTBOX',
            ],
            [
                'id' => 'queue.drain', 'label' => 'Procesar cola pendiente', 'category' => 'Colas',
                'description' => 'Procesa la cola de base de datos durante un máximo de 30 segundos y termina.',
                'command' => 'queue:work',
                'arguments' => [
                    'connection' => 'database', '--stop-when-empty' => true, '--tries' => 1,
                    '--max-time' => 30, '--no-ansi' => true,
                ],
                'mutates_state' => true, 'confirmation_phrase' => 'PROCESAR COLA',
            ],
        ];

        $result = [];
        foreach ($items as $item) {
            $result[$item['id']] = $item;
        }
        return $result;
    }

    private function verifyReauthentication(?string $password): void
    {
        $user = DB::table('users')->where('id', $this->context->userId)->first();
        if ($user === null) {
            throw new \RuntimeException('No se encontró el administrador actual.', 403);
        }

        if (is_string($user->password_hash) && $user->password_hash !== '') {
            if ($password === null || !Hash::check($password, $user->password_hash)) {
                throw new \RuntimeException('La contraseña de reautenticación es incorrecta.', 403);
            }
            return;
        }

        $token = (string) $this->request->bearerToken();
        $session = $token !== '' ? DB::table('api_sessions')->where('token_hash', hash('sha256', $token))->first() : null;
        $minutes = (int) config('developer_console.reauth_minutes', 10);
        if ($session === null || now()->diffInMinutes(Carbon::parse((string) $session->created_at)) > $minutes) {
            throw new \RuntimeException('Esta cuenta no tiene contraseña local. Inicie una sesión administrativa reciente para confirmar la acción.', 403);
        }
    }

    private function truncate(string $output): string
    {
        $limit = (int) config('developer_console.max_command_output_chars', 120000);
        if (mb_strlen($output) <= $limit) {
            return trim($output);
        }
        return trim(mb_substr($output, 0, $limit))."\n\n[Salida truncada por seguridad]";
    }
}
