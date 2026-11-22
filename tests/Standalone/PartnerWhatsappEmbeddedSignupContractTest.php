<?php

declare(strict_types=1);

function expectEmbedded(bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$required = [
    'app/Modules/Integrations/Whatsapp/Services/MetaEmbeddedSignupClient.php',
    'app/Modules/Integrations/Whatsapp/Services/PartnerWhatsappEmbeddedSignupService.php',
];
foreach ($required as $path) {
    expectEmbedded(is_file($root.'/'.$path), "Falta {$path}");
}

$routes = file_get_contents($root.'/routes/api_v1.php');
expectEmbedded(str_contains($routes, '/organization/whatsapp-channel/embedded-signup/config'), 'Falta ruta de configuración pública Embedded Signup.');
expectEmbedded(str_contains($routes, '/organization/whatsapp-channel/embedded-signup/complete'), 'Falta ruta de finalización Embedded Signup.');

$services = file_get_contents($root.'/config/services.php');
foreach (['META_APP_ID', 'META_EMBEDDED_SIGNUP_CONFIG_ID', 'META_EMBEDDED_SIGNUP_ENABLED'] as $env) {
    expectEmbedded(str_contains($services, $env), "Falta {$env} en services.php");
}

$migrations = glob($root.'/database/migrations/*embedded_signup*partner_whatsapp*.php') ?: [];
expectEmbedded(count($migrations) === 1, 'Debe existir una migración aditiva para Embedded Signup.');
$migration = file_get_contents($migrations[0]);
foreach (['connection_method', 'access_token_expires_at', 'embedded_signup_at', 'webhook_subscribed_at', 'meta_user_id', 'meta_app_id'] as $column) {
    expectEmbedded(str_contains($migration, $column), "Falta columna {$column}.");
}
expectEmbedded(!str_contains($migration, 'dropIfExists'), 'La migración no debe destruir partner_whatsapp_channels.');

$channelService = file_get_contents($root.'/app/Modules/Integrations/Whatsapp/Services/PartnerWhatsappChannelService.php');
expectEmbedded(str_contains($channelService, 'saveEmbeddedConnection'), 'Debe existir persistencia específica para Embedded Signup.');
expectEmbedded(str_contains($channelService, 'La configuración manual está reservada para SUPER_ADMIN'), 'La configuración manual debe quedar restringida a SUPER_ADMIN.');
expectEmbedded(!str_contains($channelService, "'access_token' =>"), 'El servicio nunca debe serializar access_token.');

$embeddedService = file_get_contents($root.'/app/Modules/Integrations/Whatsapp/Services/PartnerWhatsappEmbeddedSignupService.php');
expectEmbedded(str_contains($embeddedService, 'whatsapp_business_management'), 'Debe validar whatsapp_business_management.');
expectEmbedded(str_contains($embeddedService, 'phone_number_id'), 'Debe validar Phone Number ID contra la WABA.');
expectEmbedded(str_contains($embeddedService, 'subscribeWaba'), 'Debe suscribir la app al WABA.');
expectEmbedded(str_contains($embeddedService, 'saveEmbeddedConnection'), 'Debe persistir mediante el servicio del canal.');

$ui = file_get_contents($root.'/public/assets/rhino-crm.js');
foreach (['Conectar con Meta', 'FB.login', 'config_id', "response_type:'code'", 'override_default_response_type:true', 'WA_EMBEDDED_SIGNUP', '/embedded-signup/complete'] as $needle) {
    expectEmbedded(str_contains($ui, $needle), "UI Embedded Signup incompleta: {$needle}");
}
expectEmbedded(!str_contains($ui, 'META_APP_SECRET'), 'El App Secret no puede aparecer en el JS.');
expectEmbedded(str_contains($ui, 'Configuración avanzada'), 'SUPER_ADMIN debe conservar configuración manual avanzada.');

$csp = file_get_contents($root.'/app/Modules/Web/Controllers/CrmPageController.php');
expectEmbedded(str_contains($csp, 'https://connect.facebook.net'), 'CSP debe permitir cargar el Facebook JavaScript SDK.');
expectEmbedded(str_contains($csp, 'https://www.facebook.com'), 'CSP debe permitir el flujo de login de Meta.');

$controller = file_get_contents($root.'/app/Modules/Integrations/Whatsapp/Controllers/PartnerWhatsappChannelController.php');
expectEmbedded(str_contains($controller, 'embeddedSignupConfig'), 'Controlador debe exponer embeddedSignupConfig.');
expectEmbedded(str_contains($controller, 'completeEmbeddedSignup'), 'Controlador debe exponer completeEmbeddedSignup.');

fwrite(STDOUT, "PASS: Partner WhatsApp Embedded Signup contract\n");
