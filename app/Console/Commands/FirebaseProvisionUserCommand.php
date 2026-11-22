<?php

namespace App\Console\Commands;

use App\Modules\Auth\Services\RolePermissionService;
use App\Support\Database\SqlValue;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\UserNotFound;

final class FirebaseProvisionUserCommand extends Command
{
    protected $signature = 'rhino:firebase-provision
        {email : Correo del usuario ya existente en Rhino CRM}
        {--send-verification : Enviar correo de verificación si la cuenta no está verificada}
        {--trust-email : Marcar el correo como verificado; usar solo cuando la identidad fue comprobada}';

    protected $description = 'Crea o vincula de forma segura un usuario Rhino con Firebase Authentication';

    public function __construct(
        private readonly Container $container,
        private readonly RolePermissionService $rolePermissions,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (!config('firebase.auth_enabled') || !$this->container->bound(Auth::class)) {
            $this->error('Firebase no está habilitado o el SDK no está disponible.');
            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($user === null) {
            $this->error('No existe un usuario Rhino con ese correo. Créalo primero desde el CRM.');
            return self::FAILURE;
        }

        /** @var Auth $auth */
        $auth = $this->container->make(Auth::class);
        $created = false;

        try {
            $record = $auth->getUserByEmail($email);
            $this->line('La cuenta Firebase ya existe; se utilizará su UID actual.');
        } catch (UserNotFound) {
            $password = (string) $this->secret('Contraseña inicial de Firebase (mínimo 12 caracteres)');
            $confirmation = (string) $this->secret('Confirma la contraseña');
            if (mb_strlen($password) < 12 || !hash_equals($password, $confirmation)) {
                $this->error('Las contraseñas no coinciden o tienen menos de 12 caracteres.');
                return self::FAILURE;
            }

            $record = $auth->createUser([
                'email' => $email,
                'emailVerified' => (bool) $this->option('trust-email'),
                'password' => $password,
                'displayName' => (string) $user->name,
                'disabled' => false,
            ]);
            $created = true;
            $this->info('Cuenta Firebase creada.');
        }

        if ((bool) ($record->disabled ?? false)) {
            $this->error('La cuenta Firebase está deshabilitada. Actívala antes de vincularla.');
            return self::FAILURE;
        }

        $existingOwner = DB::table('users')->where('firebase_uid', $record->uid)->where('id', '!=', $user->id)->first();
        if ($existingOwner !== null) {
            $this->error('Ese UID Firebase ya está vinculado a otro usuario Rhino.');
            return self::FAILURE;
        }

        $currentUid = trim((string) ($user->firebase_uid ?? ''));
        if ($currentUid !== '' && !hash_equals($currentUid, (string) $record->uid)) {
            $this->error('El usuario Rhino ya está vinculado a otro UID Firebase.');
            return self::FAILURE;
        }

        DB::table('users')->where('id', $user->id)->update([
            'firebase_uid' => $record->uid,
            'auth_provider' => $user->password_hash !== null ? 'HYBRID' : 'FIREBASE',
            'updated_at' => now(),
        ]);

        // Store only compact claims in Firebase. Rhino resolves permissions from MySQL.
        $auth->setCustomUserClaims((string) $record->uid, [
            'partner_id' => $user->partner_id,
            'role' => (string) $user->role,
            'location_id' => $user->location_id,
        ]);

        if (!(bool) ($record->emailVerified ?? false) && $this->option('send-verification')) {
            $auth->sendEmailVerificationLink($email, [
                'continueUrl' => (string) config('app.url'),
                'handleCodeInApp' => false,
            ], 'es');
            $this->info('Firebase envió el correo de verificación.');
        }

        DB::table('security_audit_logs')->insert([
            'actor_user_id' => null,
            'partner_id' => $user->partner_id,
            'target_type' => 'USER',
            'target_id' => $user->id,
            'action' => 'FIREBASE_USER_PROVISIONED',
            'reason' => $created ? 'Cuenta creada y vinculada por comando seguro.' : 'Cuenta existente vinculada por comando seguro.',
            'ip_address' => null,
            'metadata' => SqlValue::encode(['email' => $email, 'firebase_uid_suffix' => substr((string) $record->uid, -8)]),
            'created_at' => now(),
        ]);

        $this->info('Usuario Rhino vinculado a Firebase correctamente.');
        $this->line('UID Firebase (últimos 8): '.substr((string) $record->uid, -8));
        $this->line('Correo verificado: '.(($record->emailVerified ?? false) || $this->option('trust-email') ? 'sí' : 'no'));

        return self::SUCCESS;
    }
}
