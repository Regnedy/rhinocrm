<?php

declare(strict_types=1);

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Integrations\Whatsapp\Services\PartnerWhatsappChannelResolver;
use App\Modules\Integrations\Whatsapp\Services\PartnerWhatsappChannelService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDOUT, "SKIP: Partner WhatsApp channel behavior requires pdo_sqlite\n");
    exit(0);
}

function okChannel(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => ':memory:',
    'database.connections.sqlite.foreign_key_constraints' => false,
]);
DB::purge('sqlite');
DB::setDefaultConnection('sqlite');

Schema::create('partner_whatsapp_channels', function (Blueprint $table): void {
    $table->string('id')->primary();
    $table->string('partner_id')->unique();
    $table->string('display_phone_number')->nullable();
    $table->string('phone_number_id');
    $table->string('waba_id')->nullable();
    $table->text('access_token_ciphertext');
    $table->string('pdf_template_name')->nullable();
    $table->string('images_template_name')->nullable();
    $table->string('template_language')->default('es_MX');
    $table->string('status')->default('ACTIVE');
    $table->string('connection_method')->default('MANUAL');
    $table->string('meta_user_id')->nullable();
    $table->string('meta_app_id')->nullable();
    $table->timestamp('access_token_updated_at')->nullable();
    $table->timestamp('access_token_expires_at')->nullable();
    $table->timestamp('embedded_signup_at')->nullable();
    $table->timestamp('webhook_subscribed_at')->nullable();
    $table->timestamp('last_tested_at')->nullable();
    $table->text('last_error')->nullable();
    $table->string('created_by_user_id')->nullable();
    $table->string('updated_by_user_id')->nullable();
    $table->timestamps();
});
Schema::create('security_audit_logs', function (Blueprint $table): void {
    $table->increments('id');
    $table->string('actor_user_id')->nullable();
    $table->string('partner_id')->nullable();
    $table->string('target_type');
    $table->string('target_id')->nullable();
    $table->string('action');
    $table->string('reason')->nullable();
    $table->string('ip_address')->nullable();
    $table->text('metadata')->nullable();
    $table->timestamp('created_at')->nullable();
});

$contextA = new UserContextDTO('USER-A', 'PARTNER-A', 'PARTNER_ADMIN', null, ['organization.read', 'organization.whatsapp.manage']);
$app->instance(UserContextDTO::class, $contextA);
$resolverA = new PartnerWhatsappChannelResolver($app);
$serviceA = new PartnerWhatsappChannelService($contextA, $resolverA);
$public = $serviceA->saveEmbeddedConnection([
    'display_phone_number' => '+52 662 000 0001',
    'phone_number_id' => 'PHONE_A',
    'waba_id' => 'WABA_A',
    'access_token' => 'SECRET_TOKEN_A',
    'meta_user_id' => 'META_USER_A',
    'meta_app_id' => 'APP_A',
    'pdf_template_name' => 'pdf_a',
    'images_template_name' => 'img_a',
    'template_language' => 'es_MX',
]);

$row = DB::table('partner_whatsapp_channels')->where('partner_id', 'PARTNER-A')->first();
okChannel($row !== null, 'Debe persistir el canal del socio.');
okChannel((string) $row->access_token_ciphertext !== 'SECRET_TOKEN_A', 'El token no puede persistirse en plaintext.');
okChannel(Crypt::decryptString((string) $row->access_token_ciphertext) === 'SECRET_TOKEN_A', 'El token cifrado debe poder resolverse.');
okChannel(($public['token_configured'] ?? false) === true, 'La API pública debe indicar token configurado.');
okChannel(!array_key_exists('access_token', $public) && !array_key_exists('access_token_ciphertext', $public), 'La API no debe devolver secretos.');
okChannel($resolverA->resolve()->phoneNumberId === 'PHONE_A', 'El resolver debe devolver el canal propio.');

$crossTenantBlocked = false;
try {
    $serviceA->show('PARTNER-B');
} catch (RuntimeException $e) {
    $crossTenantBlocked = $e->getCode() === 403;
}
okChannel($crossTenantBlocked, 'Un socio no puede consultar el canal de otro socio.');

$manualBlocked = false;
try {
    $serviceA->save(['phone_number_id' => 'OTHER', 'access_token' => 'NOPE']);
} catch (RuntimeException $e) {
    $manualBlocked = $e->getCode() === 403;
}
okChannel($manualBlocked, 'PARTNER_ADMIN no debe configurar credenciales manualmente.');

$contextB = new UserContextDTO('USER-B', 'PARTNER-B', 'PARTNER_ADMIN', null, ['organization.read', 'organization.whatsapp.manage']);
$app->forgetInstance(UserContextDTO::class);
$app->instance(UserContextDTO::class, $contextB);
$serviceB = new PartnerWhatsappChannelService($contextB, new PartnerWhatsappChannelResolver($app));
$serviceB->saveEmbeddedConnection([
    'phone_number_id' => 'PHONE_B',
    'waba_id' => 'WABA_B',
    'access_token' => 'SECRET_TOKEN_B',
    'meta_user_id' => 'META_USER_B',
    'meta_app_id' => 'APP_A',
    'pdf_template_name' => 'pdf_b',
    'images_template_name' => 'img_b',
]);
okChannel($resolverA->resolve('PARTNER-A')->phoneNumberId === 'PHONE_A', 'Partner A debe conservar su canal.');
$resolverB = new PartnerWhatsappChannelResolver($app);
okChannel($resolverB->resolve()->phoneNumberId === 'PHONE_B', 'Partner B debe usar un canal diferente.');

fwrite(STDOUT, "PASS: Partner WhatsApp channel encrypted tenant behavior\n");
