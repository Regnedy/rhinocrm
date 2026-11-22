<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Factory;

final class FirebaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!config('firebase.auth_enabled')) {
            return;
        }

        if (!class_exists(Factory::class)) {
            throw new \RuntimeException('Firebase está habilitado, pero falta instalar kreait/firebase-php.');
        }

        $this->app->singleton(Auth::class, function (): Auth {
            $projectId = trim((string) config('firebase.project_id'));
            $factory = new Factory();

            if ($projectId !== '') {
                $factory = $factory->withProjectId($projectId);
            }

            if (config('firebase.emulator.enabled')) {
                $host = trim((string) config('firebase.emulator.auth_host'));
                if ($host === '') {
                    throw new \RuntimeException('FIREBASE_AUTH_EMULATOR_HOST no está configurado.');
                }
                putenv('FIREBASE_AUTH_EMULATOR_HOST='.$host);

                if ($projectId === '') {
                    throw new \RuntimeException('FIREBASE_PROJECT_ID es obligatorio al usar el emulador.');
                }
            } else {
                $credentials = trim((string) config('firebase.credentials'));
                if ($credentials === '') {
                    throw new \RuntimeException('FIREBASE_CREDENTIALS no está configurado.');
                }

                $credentialsPath = str_starts_with($credentials, DIRECTORY_SEPARATOR)
                    ? $credentials
                    : base_path($credentials);

                if (!is_file($credentialsPath) || !is_readable($credentialsPath)) {
                    throw new \RuntimeException('FIREBASE_CREDENTIALS no apunta a un archivo JSON legible.');
                }

                $factory = $factory->withServiceAccount($credentialsPath);
            }

            return $factory->createAuth();
        });
    }
}
