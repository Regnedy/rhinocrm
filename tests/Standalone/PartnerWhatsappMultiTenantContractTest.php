<?php

declare(strict_types=1);

function expectPartnerWhatsapp(bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$required = [
    'app/Modules/Integrations/Whatsapp/DTOs/PartnerWhatsappChannelDTO.php',
    'app/Modules/Integrations/Whatsapp/Services/PartnerWhatsappChannelResolver.php',
    'app/Modules/Integrations/Whatsapp/Services/PartnerWhatsappChannelService.php',
    'app/Modules/Integrations/Whatsapp/Controllers/PartnerWhatsappChannelController.php',
];
foreach ($required as $path) {
    expectPartnerWhatsapp(is_file($root.'/'.$path), "Falta {$path}");
}

$migrations = glob($root.'/database/migrations/*partner_whatsapp_channel*.php') ?: [];
expectPartnerWhatsapp(count($migrations) >= 1, 'Debe existir la migración base partner_whatsapp_channel.');
$createMigrations = array_values(array_filter($migrations, fn(string $path): bool => str_contains((string) file_get_contents($path), "Schema::create('partner_whatsapp_channels'")));
expectPartnerWhatsapp(count($createMigrations) === 1, 'Debe existir una sola migración que cree partner_whatsapp_channels.');
$migration = file_get_contents($createMigrations[0]);
expectPartnerWhatsapp(str_contains($migration, "->unique()"), 'partner_id debe ser único en esta fase.');
expectPartnerWhatsapp(str_contains($migration, 'access_token_ciphertext'), 'Debe persistir token cifrado, no plaintext.');
foreach ($migrations as $migrationPath) {
    $migrationBody = file_get_contents($migrationPath);
    expectPartnerWhatsapp(!str_contains($migrationBody, "Schema::dropIfExists('partner_whatsapp_channels')"), 'Ningún down debe destruir la tabla.');
}

$resolver = file_get_contents($root.'/app/Modules/Integrations/Whatsapp/Services/PartnerWhatsappChannelResolver.php');
expectPartnerWhatsapp(str_contains($resolver, "Crypt::decryptString"), 'Resolver debe descifrar el token al usarlo.');
expectPartnerWhatsapp(str_contains($resolver, "where('partner_id', \$partnerId)"), 'Resolver debe buscar estrictamente por partner_id.');
expectPartnerWhatsapp(!str_contains($resolver, "config('services.meta.access_token')"), 'No debe hacer fallback silencioso al token global.');
expectPartnerWhatsapp(str_contains($resolver, 'access_token_expires_at'), 'El resolver debe bloquear credenciales Embedded Signup expiradas.');

$service = file_get_contents($root.'/app/Modules/Integrations/Whatsapp/Services/PartnerWhatsappChannelService.php');
expectPartnerWhatsapp(str_contains($service, 'Crypt::encryptString'), 'Servicio debe cifrar el token antes de persistirlo.');
expectPartnerWhatsapp(str_contains($service, "organization.whatsapp.manage"), 'Servicio debe exigir permiso específico.');
expectPartnerWhatsapp(!str_contains($service, "'access_token' =>"), 'API no debe serializar access_token.');

$provider = file_get_contents($root.'/app/Modules/Integrations/Whatsapp/Providers/MetaWhatsappProvider.php');
expectPartnerWhatsapp(str_contains($provider, 'WhatsappChannelResolverInterface'), 'Meta provider debe resolver canal del socio.');
expectPartnerWhatsapp(str_contains($provider, '?string $partnerId = null'), 'Operaciones deben aceptar partnerId opcional.');
expectPartnerWhatsapp(!str_contains($provider, "config('services.meta.phone_number_id')"), 'Provider no debe usar Phone Number ID global para envíos de socios.');

$delivery = file_get_contents($root.'/app/Modules/NutritionEngine/Services/NutritionPlanDeliveryService.php');
expectPartnerWhatsapp(str_contains($delivery, '(string) $plan->partner_id'), 'Quick Nutrition debe pasar explícitamente el partner propietario.');

$permissions = file_get_contents($root.'/app/Modules/Auth/Services/RolePermissionService.php');
expectPartnerWhatsapp(str_contains($permissions, "'organization.whatsapp.manage'"), 'PARTNER_ADMIN debe tener permiso de administración WhatsApp.');

$routes = file_get_contents($root.'/routes/api_v1.php');
expectPartnerWhatsapp(str_contains($routes, '/organization/whatsapp-channel'), 'Deben existir rutas de configuración WhatsApp del socio.');

$inbound = file_get_contents($root.'/app/Modules/Integrations/Whatsapp/Services/WhatsappInboundService.php');
expectPartnerWhatsapp(str_contains($inbound, "partner_whatsapp_channels"), 'Webhook entrante debe resolver el socio por el canal receptor.');
expectPartnerWhatsapp(str_contains($inbound, "phone_number_id"), 'Webhook entrante debe usar metadata.phone_number_id.');

$ui = file_get_contents($root.'/public/assets/rhino-crm.js');
expectPartnerWhatsapp(str_contains($ui, 'WhatsApp del socio'), 'La UI debe mostrar la tarjeta WhatsApp del socio.');
expectPartnerWhatsapp(str_contains($ui, 'access_token'), 'El formulario debe permitir capturar un token nuevo.');
expectPartnerWhatsapp(preg_match('/channel\.access_token(?!_)/', $ui) !== 1, 'La UI nunca debe renderizar el token almacenado.');

fwrite(STDOUT, "PASS: Partner WhatsApp multi-tenant contract\n");
