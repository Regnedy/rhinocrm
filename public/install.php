<?php

declare(strict_types=1);

session_start();

$basePath = dirname(__DIR__);
$lockPath = $basePath.'/storage/app/installed.lock';
$envPath = $basePath.'/.env';
$autoloadPath = $basePath.'/vendor/autoload.php';
$errors = [];
$success = null;
$installationOutput = [];

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function requirement(string $label, bool $passed, string $detail = ''): array
{
    return compact('label', 'passed', 'detail');
}

function envQuote(string $value): string
{
    if ($value === '') {
        return '';
    }
    if (preg_match('/^[A-Za-z0-9_\.\-:\/]+$/', $value)) {
        return $value;
    }
    return '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', ''], $value).'"';
}

/** @param array<string,string> $values */
function writeEnvironment(string $path, string $templatePath, array $values): void
{
    $contents = is_file($path) ? (string) file_get_contents($path) : (is_file($templatePath) ? (string) file_get_contents($templatePath) : '');
    $lines = preg_split('/\R/', $contents) ?: [];
    $seen = [];
    foreach ($lines as $index => $line) {
        if (!preg_match('/^([A-Z0-9_]+)=/', $line, $matches)) {
            continue;
        }
        $key = $matches[1];
        if (array_key_exists($key, $values)) {
            $lines[$index] = $key.'='.envQuote($values[$key]);
            $seen[$key] = true;
        }
    }
    foreach ($values as $key => $value) {
        if (!isset($seen[$key])) {
            $lines[] = $key.'='.envQuote($value);
        }
    }
    $payload = rtrim(implode(PHP_EOL, $lines)).PHP_EOL;
    $temporary = $path.'.tmp.'.bin2hex(random_bytes(4));
    if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
        throw new RuntimeException('No fue posible escribir el archivo temporal de configuración.');
    }
    @chmod($temporary, 0640);
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('No fue posible reemplazar el archivo .env. Revise permisos.');
    }
}

function detectDatabaseTables(PDO $pdo, string $database): array
{
    $statement = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME');
    $statement->execute([$database]);
    return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

/** @return list<string> */
function detectTableColumns(PDO $pdo, string $database, string $table): array
{
    $statement = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
    $statement->execute([$database, $table]);
    return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

/** @param list<string> $existingTables */
function assertCompatibleExistingDatabase(PDO $pdo, string $database, array $existingTables): void
{
    $expected = [
        'partners' => ['id', 'plan_id', 'business_name', 'status'],
        'users' => ['id', 'partner_id', 'role', 'email', 'status'],
        'contacts' => ['id', 'partner_id', 'whatsapp_normalized', 'sales_stage', 'status'],
        'devices' => ['id', 'partner_id', 'location_id', 'device_token', 'status'],
        'sales' => ['id', 'partner_id', 'contact_id', 'status', 'total_revenue_minor'],
        'jobs' => ['id', 'queue', 'payload', 'available_at'],
    ];

    foreach ($expected as $table => $requiredColumns) {
        if (!in_array($table, $existingTables, true)) {
            continue;
        }
        $columns = detectTableColumns($pdo, $database, $table);
        $missing = array_values(array_diff($requiredColumns, $columns));
        if ($missing !== []) {
            throw new RuntimeException("La tabla existente {$table} no corresponde a Rhino CRM. Faltan columnas: ".implode(', ', $missing).'. Use una base separada para no alterar otra aplicación.');
        }
    }

    if (in_array('migrations', $existingTables, true) && !in_array('system_settings', $existingTables, true)) {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
        if ($count > 0) {
            throw new RuntimeException('La base contiene un historial de migraciones de otra instalación y no presenta la huella de Rhino CRM. Use una base nueva o una copia validada.');
        }
    }
}

function renderOutput(string $text): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: [])));
}

$directories = [
    $basePath.'/storage',
    $basePath.'/storage/app',
    $basePath.'/storage/app/private',
    $basePath.'/storage/framework',
    $basePath.'/storage/framework/cache',
    $basePath.'/storage/framework/cache/data',
    $basePath.'/storage/framework/sessions',
    $basePath.'/storage/framework/views',
    $basePath.'/storage/logs',
    $basePath.'/bootstrap/cache',
];
foreach ($directories as $directory) {
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }
}

$requirements = [
    requirement('PHP 8.3 o superior', version_compare(PHP_VERSION, '8.3.0', '>='), PHP_VERSION),
    requirement('Composer/vendor instalado', is_file($autoloadPath), is_file($autoloadPath) ? 'Disponible' : 'Ejecute composer install --no-dev --optimize-autoloader'),
    requirement('PDO', extension_loaded('pdo')),
    requirement('PDO MySQL', extension_loaded('pdo_mysql')),
    requirement('OpenSSL', extension_loaded('openssl')),
    requirement('Mbstring', extension_loaded('mbstring')),
    requirement('BCMath', extension_loaded('bcmath')),
    requirement('JSON', extension_loaded('json')),
    requirement('Fileinfo', extension_loaded('fileinfo')),
    requirement('storage/ escribible', is_writable($basePath.'/storage')),
    requirement('bootstrap/cache escribible', is_writable($basePath.'/bootstrap/cache')),
    requirement('.env escribible', (is_file($envPath) && is_writable($envPath)) || (!is_file($envPath) && is_writable($basePath))),
];
$requirementsPassed = !in_array(false, array_column($requirements, 'passed'), true);
$installerDisabled = is_file($envPath) && preg_match('/^RHINO_INSTALLER_ENABLED=(false|0|no|off)$/mi', (string) file_get_contents($envPath)) === 1;
$alreadyInstalled = is_file($lockPath) || $installerDisabled;

if (!isset($_SESSION['rhino_installer_csrf'])) {
    $_SESSION['rhino_installer_csrf'] = bin2hex(random_bytes(32));
}

$defaults = [
    'app_name' => 'Rhino CRM',
    'app_url' => ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? 'localhost'),
    'timezone' => 'America/Mazatlan',
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_name' => '',
    'db_user' => '',
    'admin_name' => 'Administrador Rhino',
    'admin_email' => '',
];
$form = array_merge($defaults, array_intersect_key($_POST, $defaults));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyInstalled) {
    try {
        if (!$requirementsPassed) {
            throw new RuntimeException('No se cumplen todos los requisitos del servidor.');
        }
        if (!hash_equals((string) $_SESSION['rhino_installer_csrf'], (string) ($_POST['_token'] ?? ''))) {
            throw new RuntimeException('La sesión del instalador expiró. Recargue la página.');
        }

        $required = ['app_name','app_url','timezone','db_host','db_port','db_name','db_user','admin_name','admin_email','admin_password','admin_password_confirmation'];
        foreach ($required as $field) {
            if (trim((string) ($_POST[$field] ?? '')) === '') {
                throw new InvalidArgumentException('Todos los campos obligatorios deben completarse.');
            }
        }
        if (!filter_var($_POST['admin_email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('El correo administrativo no es válido.');
        }
        if (strlen((string) $_POST['admin_password']) < 12) {
            throw new InvalidArgumentException('La contraseña administrativa debe tener al menos 12 caracteres.');
        }
        if (!hash_equals((string) $_POST['admin_password'], (string) $_POST['admin_password_confirmation'])) {
            throw new InvalidArgumentException('Las contraseñas administrativas no coinciden.');
        }
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', (string) $_POST['db_name'])) {
            throw new InvalidArgumentException('El nombre de la base de datos contiene caracteres no permitidos.');
        }
        if (empty($_POST['backup_confirmed'])) {
            throw new InvalidArgumentException('Debe confirmar que dispone de una copia de seguridad si usará una base existente.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', trim((string) $_POST['db_host']), (int) $_POST['db_port'], trim((string) $_POST['db_name']));
        $pdo = new PDO($dsn, (string) $_POST['db_user'], (string) ($_POST['db_password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        $pdo->query('SELECT 1');
        $existingTables = detectDatabaseTables($pdo, (string) $_POST['db_name']);
        assertCompatibleExistingDatabase($pdo, (string) $_POST['db_name'], $existingTables);

        $appKey = 'base64:'.base64_encode(random_bytes(32));
        $contactHashKey = bin2hex(random_bytes(32));
        writeEnvironment($envPath, $basePath.'/.env.example', [
            'APP_NAME' => trim((string) $_POST['app_name']),
            'APP_ENV' => 'production',
            'APP_KEY' => $appKey,
            'APP_DEBUG' => 'false',
            'APP_URL' => rtrim(trim((string) $_POST['app_url']), '/'),
            'APP_TIMEZONE' => trim((string) $_POST['timezone']),
            'APP_LOCALE' => 'es',
            'LOG_CHANNEL' => 'stack',
            'LOG_LEVEL' => 'warning',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => trim((string) $_POST['db_host']),
            'DB_PORT' => (string) (int) $_POST['db_port'],
            'DB_DATABASE' => trim((string) $_POST['db_name']),
            'DB_USERNAME' => trim((string) $_POST['db_user']),
            'DB_PASSWORD' => (string) ($_POST['db_password'] ?? ''),
            'CACHE_STORE' => 'database',
            'QUEUE_CONNECTION' => 'database',
            'QUEUE_FAILED_DRIVER' => 'database-uuids',
            'SESSION_DRIVER' => 'database',
            'FILESYSTEM_DISK' => 'local',
            'RHINO_REPORT_DISK' => 'local',
            'RHINO_AUTH_DRIVER' => 'local',
            'RHINO_SESSION_DAYS' => '30',
            'RHINO_SESSION_TOUCH_SECONDS' => '300',
            'FIREBASE_AUTH_ENABLED' => 'false',
            'FIREBASE_PROJECT_ID' => '',
            'FIREBASE_CREDENTIALS' => '',
            'FIREBASE_EMULATOR_ENABLED' => 'false',
            'CONTACT_ID_HASH_KEY' => $contactHashKey,
            'RHINO_INSTALLER_ENABLED' => 'true',
            'RHINO_VERSION' => '2026.07.21-events.1',
            'RHINO_DEV_CONSOLE_ENABLED' => 'true',
            'RHINO_DEV_CONSOLE_ALLOWED_IPS' => '',
            'RHINO_DEV_CONSOLE_REDACT_PII' => 'true',
            'RHINO_DEV_CONSOLE_AI_ENABLED' => 'false',
            'OPENAI_API_BASE' => 'https://api.openai.com/v1',
            'OPENAI_API_KEY' => '',
            'OPENAI_MODEL' => '',
        ]);

        require $autoloadPath;
        $app = require $basePath.'/bootstrap/app.php';
        /** @var Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();

        Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        $installationOutput = array_merge($installationOutput, renderOutput(Illuminate\Support\Facades\Artisan::output()));
        Illuminate\Support\Facades\Artisan::call('optimize:clear');
        $installationOutput = array_merge($installationOutput, renderOutput(Illuminate\Support\Facades\Artisan::output()));
        Illuminate\Support\Facades\Artisan::call('rhino:bootstrap', ['--force' => true]);
        $installationOutput = array_merge($installationOutput, renderOutput(Illuminate\Support\Facades\Artisan::output()));

        $email = mb_strtolower(trim((string) $_POST['admin_email']));
        $existingAdmin = Illuminate\Support\Facades\DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($existingAdmin !== null && (string) $existingAdmin->role !== 'SUPER_ADMIN') {
            throw new RuntimeException('Ese correo ya pertenece a un usuario que no es SUPER_ADMIN. Use otro correo para evitar alterar una cuenta existente.');
        }
        $adminId = $existingAdmin?->id ?: 'ADM_'.strtoupper((string) Illuminate\Support\Str::ulid());
        Illuminate\Support\Facades\DB::table('users')->updateOrInsert(['id' => $adminId], [
            'partner_id' => null,
            'location_id' => null,
            'role' => 'SUPER_ADMIN',
            'name' => trim((string) $_POST['admin_name']),
            'email' => $email,
            'password_hash' => Illuminate\Support\Facades\Hash::make((string) $_POST['admin_password']),
            'auth_provider' => 'LOCAL',
            'firebase_uid' => null,
            'permissions' => json_encode(['*'], JSON_THROW_ON_ERROR),
            'status' => 'ACTIVE',
            'suspended_at' => null,
            'suspended_by' => null,
            'suspension_reason' => null,
            'created_at' => $existingAdmin?->created_at ?? now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
        Illuminate\Support\Facades\DB::table('deployment_logs')->insert([
            'version' => 'mysql-console-installer-2026.07.20',
            'status' => 'INSTALLED',
            'database_backup' => 'Confirmado por el usuario antes de instalar',
            'migrations' => json_encode(['mode' => 'additive', 'existing_tables_before_install' => $existingTables], JSON_THROW_ON_ERROR),
            'notes' => 'Instalación web. No se ejecutaron migrate:fresh, reset, rollback ni db:wipe.',
            'deployed_at' => now(),
        ]);

        $lockPayload = json_encode([
            'installed_at' => date(DATE_ATOM),
            'version' => '2026.07.21-events.1',
            'database' => (string) $_POST['db_name'],
            'admin_email' => $email,
            'migration_policy' => 'additive-only',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($lockPath, $lockPayload, LOCK_EX) === false) {
            throw new RuntimeException('La instalación terminó, pero no se pudo crear installed.lock. Créelo manualmente para bloquear el instalador.');
        }
        @chmod($lockPath, 0640);
        writeEnvironment($envPath, $basePath.'/.env.example', ['RHINO_INSTALLER_ENABLED' => 'false']);
        $success = 'Rhino CRM se instaló correctamente. El instalador quedó bloqueado.';
        $alreadyInstalled = true;
        unset($_SESSION['rhino_installer_csrf']);
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
    }
}

$step = $alreadyInstalled ? 3 : ($requirementsPassed ? 2 : 1);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Instalador de Rhino CRM</title>
<style>
:root{color-scheme:dark;--bg:#0b1020;--panel:#121a2d;--line:#29344f;--muted:#98a5be;--text:#edf2ff;--accent:#4f8cff;--ok:#3ddc97;--bad:#ff6b7a;--warn:#ffc857}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top,#15213d 0,#0b1020 48%);font:15px/1.55 system-ui,-apple-system,Segoe UI,sans-serif;color:var(--text)}main{max-width:980px;margin:36px auto;padding:0 20px 60px}.brand{display:flex;gap:16px;align-items:center;margin-bottom:22px}.logo{display:grid;place-items:center;width:54px;height:54px;border-radius:15px;background:linear-gradient(145deg,#4f8cff,#7d5cff);font-size:25px;font-weight:800;box-shadow:0 14px 35px #0008}.brand h1{margin:0;font-size:25px}.brand p{margin:3px 0 0;color:var(--muted)}.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:22px 0}.step{border:1px solid var(--line);background:#10182a;padding:12px;border-radius:12px;color:var(--muted)}.step.active{border-color:var(--accent);color:var(--text);box-shadow:0 0 0 1px var(--accent) inset}.step strong{display:block;color:inherit}.card{background:color-mix(in srgb,var(--panel) 94%,transparent);border:1px solid var(--line);border-radius:18px;padding:24px;margin-top:18px;box-shadow:0 25px 70px #0005}.card h2{margin:0 0 15px;font-size:20px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.full{grid-column:1/-1}label{display:block;font-weight:650;margin-bottom:6px}input,select{width:100%;border:1px solid var(--line);background:#0b1325;color:var(--text);padding:11px 12px;border-radius:10px;outline:none}input:focus,select:focus{border-color:var(--accent);box-shadow:0 0 0 3px #4f8cff22}.hint{font-size:12px;color:var(--muted);margin-top:5px}.checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.check{display:flex;justify-content:space-between;gap:12px;padding:10px 12px;background:#0d1527;border:1px solid var(--line);border-radius:10px}.pass{color:var(--ok)}.fail{color:var(--bad)}.alert{padding:13px 15px;border-radius:11px;margin:12px 0;border:1px solid}.alert.error{background:#351725;border-color:#7b2b40;color:#ffdce2}.alert.success{background:#123326;border-color:#276b4d;color:#d9ffec}.alert.warn{background:#362d13;border-color:#725e22;color:#fff1bd}.actions{display:flex;align-items:center;justify-content:flex-end;gap:12px;margin-top:22px}button,.button{appearance:none;border:0;border-radius:10px;padding:12px 18px;background:linear-gradient(135deg,#4f8cff,#725cff);color:white;font-weight:750;cursor:pointer;text-decoration:none}button:disabled{opacity:.45;cursor:not-allowed}.checkbox{display:flex;gap:10px;align-items:flex-start;padding:12px;background:#0d1527;border:1px solid var(--line);border-radius:10px}.checkbox input{width:auto;margin-top:4px}.terminal{background:#060a13;border:1px solid #222c42;border-radius:11px;padding:14px;max-height:260px;overflow:auto;color:#bcd0f7;font:12px/1.55 ui-monospace,SFMono-Regular,Consolas,monospace}.small{font-size:13px;color:var(--muted)}code{background:#08101f;padding:2px 5px;border-radius:5px}@media(max-width:720px){.grid,.checks{grid-template-columns:1fr}.steps{grid-template-columns:1fr}.full{grid-column:auto}}
</style>
</head>
<body><main>
<div class="brand"><div class="logo">R</div><div><h1>Rhino CRM</h1><p>Instalación segura para MySQL y hosting compartido</p></div></div>
<div class="steps">
  <div class="step <?= $step===1?'active':'' ?>"><strong>1. Servidor</strong>Requisitos y permisos</div>
  <div class="step <?= $step===2?'active':'' ?>"><strong>2. Configuración</strong>Base de datos y administrador</div>
  <div class="step <?= $step===3?'active':'' ?>"><strong>3. Finalización</strong>Bloqueo del instalador</div>
</div>

<?php foreach ($errors as $error): ?><div class="alert error"><?= h($error) ?></div><?php endforeach; ?>
<?php if ($success): ?><div class="alert success"><?= h($success) ?></div><?php endif; ?>

<div class="card"><h2>Comprobación del servidor</h2><div class="checks">
<?php foreach ($requirements as $item): ?><div class="check"><span><?= h($item['label']) ?><?php if($item['detail']): ?><div class="hint"><?= h($item['detail']) ?></div><?php endif; ?></span><strong class="<?= $item['passed']?'pass':'fail' ?>"><?= $item['passed']?'Correcto':'Revisar' ?></strong></div><?php endforeach; ?>
</div>
<?php if (!is_file($autoloadPath)): ?><div class="alert warn">Antes de continuar, ejecute por SSH en la carpeta del CRM: <code>composer install --no-dev --optimize-autoloader</code>. El instalador no descarga dependencias por Internet.</div><?php endif; ?>
</div>

<?php if ($alreadyInstalled): ?>
<div class="card"><h2>Instalación bloqueada</h2><p>Rhino CRM ya está instalado o el instalador fue deshabilitado. El bloqueo impide volver a ejecutar migraciones o reemplazar la cuenta administrativa desde la web.</p><p class="small">Para una actualización futura use las migraciones incrementales y haga una copia de seguridad. No elimine el bloqueo salvo que esté realizando una recuperación controlada.</p><p class="small">La consola técnica está disponible exclusivamente para el <code>SUPER_ADMIN</code>. No ofrece shell ni SQL arbitrario.</p><div class="actions"><a class="button" href="./">Abrir API</a><a class="button" href="./admin/developer-console">Abrir consola técnica</a></div></div>
<?php elseif ($requirementsPassed): ?>
<form method="post" class="card" autocomplete="off">
<input type="hidden" name="_token" value="<?= h($_SESSION['rhino_installer_csrf']) ?>">
<h2>Configuración de la aplicación</h2>
<div class="grid">
<div><label>Nombre del CRM</label><input name="app_name" required value="<?= h($form['app_name']) ?>"></div>
<div><label>URL pública</label><input name="app_url" type="url" required value="<?= h($form['app_url']) ?>"><div class="hint">Ejemplo: https://crm.midominio.com</div></div>
<div class="full"><label>Zona horaria</label><select name="timezone"><option value="America/Mazatlan" <?= $form['timezone']==='America/Mazatlan'?'selected':'' ?>>America/Mazatlan</option><option value="America/Mexico_City" <?= $form['timezone']==='America/Mexico_City'?'selected':'' ?>>America/Mexico_City</option><option value="UTC" <?= $form['timezone']==='UTC'?'selected':'' ?>>UTC</option></select></div>
</div>
<h2 style="margin-top:28px">Base de datos MySQL</h2>
<div class="grid">
<div><label>Servidor</label><input name="db_host" required value="<?= h($form['db_host']) ?>"></div>
<div><label>Puerto</label><input name="db_port" type="number" min="1" max="65535" required value="<?= h($form['db_port']) ?>"></div>
<div><label>Base de datos</label><input name="db_name" required value="<?= h($form['db_name']) ?>"></div>
<div><label>Usuario MySQL</label><input name="db_user" required value="<?= h($form['db_user']) ?>"></div>
<div class="full"><label>Contraseña MySQL</label><input name="db_password" type="password" value=""><div class="hint">Se guardará únicamente en el archivo .env del servidor.</div></div>
</div>
<h2 style="margin-top:28px">Administrador global</h2>
<div class="grid">
<div><label>Nombre</label><input name="admin_name" required value="<?= h($form['admin_name']) ?>"></div>
<div><label>Correo</label><input name="admin_email" type="email" required value="<?= h($form['admin_email']) ?>"></div>
<div><label>Contraseña</label><input name="admin_password" type="password" minlength="12" required></div>
<div><label>Confirmar contraseña</label><input name="admin_password_confirmation" type="password" minlength="12" required></div>
<div class="full checkbox"><input id="backup_confirmed" type="checkbox" name="backup_confirmed" value="1" required><label for="backup_confirmed" style="margin:0">Confirmo que tengo una copia de seguridad si esta base de datos contiene información. El instalador solo ejecutará migraciones aditivas y no usará <code>migrate:fresh</code>, <code>reset</code>, <code>rollback</code> ni <code>db:wipe</code>.</label></div>
</div>
<div class="actions"><button type="submit">Probar conexión e instalar</button></div>
</form>
<?php endif; ?>

<?php if ($installationOutput): ?><div class="card"><h2>Registro de instalación</h2><div class="terminal"><?php foreach($installationOutput as $line): ?><div><?= h($line) ?></div><?php endforeach; ?></div></div><?php endif; ?>
<div class="card small"><strong>Firebase pendiente:</strong> la instalación inicial usa sesiones locales revocables. Más adelante podrá configurarse <code>FIREBASE_AUTH_ENABLED=true</code> y <code>RHINO_AUTH_DRIVER=hybrid</code> sin reiniciar las tablas ni cambiar los identificadores internos.</div>
</main></body></html>
