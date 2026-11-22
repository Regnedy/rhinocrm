<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Kreait\Firebase\Contract\Auth;

final class FirebaseCheckCommand extends Command
{
    protected $signature = 'rhino:firebase-check {--email= : Correo Firebase opcional que se desea consultar}';
    protected $description = 'Comprueba la configuración y conectividad de Firebase Authentication sin mostrar secretos';

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->line('Rhino auth driver: '.(string) config('rhino.auth.driver', 'local'));
        $this->line('Firebase habilitado: '.(config('firebase.auth_enabled') ? 'sí' : 'no'));
        $this->line('Firebase project ID: '.((string) config('firebase.project_id') ?: '(no configurado)'));

        $credentials = trim((string) config('firebase.credentials'));
        $credentialsPath = $credentials !== '' && !str_starts_with($credentials, DIRECTORY_SEPARATOR)
            ? base_path($credentials)
            : $credentials;
        $this->line('Credencial legible: '.($credentialsPath !== '' && is_readable($credentialsPath) ? 'sí' : 'no'));
        $this->line('SDK instalado: '.(class_exists(\Kreait\Firebase\Factory::class) ? 'sí' : 'no'));

        if (!config('firebase.auth_enabled')) {
            $this->warn('Firebase está deshabilitado. Configura .env y ejecuta php artisan optimize:clear.');
            return self::FAILURE;
        }

        try {
            /** @var Auth $auth */
            $auth = $this->container->make(Auth::class);
            $email = mb_strtolower(trim((string) $this->option('email')));

            if ($email !== '') {
                $record = $auth->getUserByEmail($email);
                $this->info('Conexión correcta. Usuario encontrado.');
                $this->table(
                    ['Campo', 'Valor'],
                    [
                        ['UID (últimos 8)', substr((string) $record->uid, -8)],
                        ['Correo', (string) ($record->email ?? '')],
                        ['Correo verificado', ($record->emailVerified ?? false) ? 'sí' : 'no'],
                        ['Deshabilitado', ($record->disabled ?? false) ? 'sí' : 'no'],
                    ],
                );
            } else {
                // Fuerza una petición autenticada al servicio sin necesitar que existan usuarios.
                foreach ($auth->listUsers(1, 1) as $record) {
                    break;
                }
                $this->info('Conexión correcta con Firebase Authentication.');
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Firebase no respondió correctamente: '.$exception->getMessage());
            return self::FAILURE;
        }
    }
}
