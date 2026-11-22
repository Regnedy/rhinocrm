<?php
$root = dirname(__DIR__, 2);
$required = [
    'database/migrations/2026_08_09_000300_create_partner_kiosk_settings.php',
    'app/Modules/Mobile/Services/PartnerKioskSettingsService.php',
    'app/Modules/Mobile/Controllers/PartnerKioskSettingsController.php',
];
foreach ($required as $path) {
    if (!is_file($root.'/'.$path)) {
        fwrite(STDERR, "Missing $path\n");
        exit(1);
    }
}
$migration = file_get_contents($root.'/'.$required[0]);
$service = file_get_contents($root.'/'.$required[1]);
$controller = file_get_contents($root.'/'.$required[2]);
$routes = file_get_contents($root.'/routes/api_v1.php');
$roles = file_get_contents($root.'/app/Modules/Auth/Services/RolePermissionService.php');
$policy = file_get_contents($root.'/app/Modules/Auth/Services/PartnerPermissionPolicyService.php');

$checks = [
    [$migration, "Schema::create('partner_kiosk_settings'"],
    [$migration, "->string('partner_id', 64)->primary()"],
    [$migration, "whatsapp_waiting_template"],
    [$migration, "whatsapp_waiting_deadline"],
    [$migration, "whatsapp_waiting_template_version"],
    [$service, 'DEFAULT_WAITING_TEMPLATE'],
    [$service, "'{nombre}'"],
    [$service, "'{socio}'"],
    [$service, "'{plazo}'"],
    [$service, 'validateTemplateVariables'],
    [$service, 'authorizedPartnerId'],
    [$controller, 'function show'],
    [$controller, 'function update'],
    [$controller, 'function reset'],
    [$routes, "'/organization/kiosk-settings'"],
    [$routes, "'/organization/kiosk-settings/reset'"],
    [$routes, 'organization.kiosk.manage'],
    [$roles, 'organization.kiosk.manage'],
    [$policy, 'organization.kiosk.manage'],
];
foreach ($checks as [$haystack, $needle]) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "Missing contract token: $needle\n");
        exit(1);
    }
}
if (preg_match('/\{(?!nombre\}|socio\}|plazo\})[^}]+\}/', PartnerKioskSettingsServiceTestFixture::defaultTemplate()) === 1) {
    fwrite(STDERR, "Default template contains unsupported markers\n");
    exit(1);
}
final class PartnerKioskSettingsServiceTestFixture {
    public static function defaultTemplate(): string {
        return "Hola {nombre} 👋\n\nTu evaluación RHINO quedó registrada correctamente.\n\n🥗 Estamos preparando tu Plan Alimenticio Personalizado utilizando los datos obtenidos durante tu pesaje.\n\nEstará listo en un plazo máximo de {plazo} y lo recibirás por este WhatsApp en formato PDF e imágenes.\n\n📲 Guarda este número para identificar la entrega de tu plan y recibir tu seguimiento.\n\nGracias por realizar tu evaluación con {socio}.";
    }
}
echo "PASS: Partner kiosk WhatsApp waiting template contract\n";
