<?php

declare(strict_types=1);

function expectDirect(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$provider = file_get_contents($root.'/app/Modules/Integrations/Whatsapp/Providers/MetaWhatsappProvider.php');
$contract = file_get_contents($root.'/app/Modules/Integrations/Whatsapp/Contracts/WhatsappProviderInterface.php');
$delivery = file_get_contents($root.'/app/Modules/NutritionEngine/Services/NutritionPlanDeliveryService.php');
$controller = file_get_contents($root.'/app/Modules/NutritionEngine/Controllers/NutritionEngineController.php');
$routes = file_get_contents($root.'/routes/api_v1.php');

expectDirect(str_contains($contract, 'uploadImage'), 'El proveedor debe poder cargar imágenes a Meta.');
expectDirect(str_contains($contract, 'sendImage('), 'El proveedor debe poder enviar imágenes directas.');
expectDirect(str_contains($contract, 'sendImageTemplate'), 'El proveedor debe soportar plantilla con encabezado de imagen.');
expectDirect(str_contains($provider, "'type' => 'image'"), 'Meta provider debe construir mensajes image.');
expectDirect(str_contains($delivery, "'format' => strtoupper(\$format)"), 'La entrega debe reportar el formato solicitado.');
expectDirect(str_contains($delivery, 'sendImagesDirect'), 'La entrega debe implementar envío múltiple de imágenes.');
expectDirect(str_contains($delivery, 'captureKioskConsent'), 'La captura del Kiosco debe registrar consentimiento de entrega.');
expectDirect(str_contains($controller, 'sendWhatsappMobile'), 'Debe existir endpoint móvil de envío directo.');
expectDirect(str_contains($controller, 'testWhatsappDelivery'), 'Debe existir endpoint de prueba sin persistencia.');
expectDirect(str_contains($routes, "/mobile/nutrition-plans/{id}/send-whatsapp"), 'Debe existir ruta móvil de envío directo.');
expectDirect(str_contains($routes, '/mobile/whatsapp/test-delivery'), 'Debe existir ruta móvil de test directo.');

fwrite(STDOUT, "PASS: Quick Nutrition direct media WhatsApp contract\n");
