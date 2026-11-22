<?php
$root = dirname(__DIR__, 2);
$delivery = file_get_contents($root.'/app/Modules/NutritionEngine/Services/NutritionPlanDeliveryService.php');
$controller = file_get_contents($root.'/app/Modules/NutritionEngine/Controllers/NutritionEngineController.php');
$testService = file_get_contents($root.'/app/Modules/NutritionEngine/Services/QuickNutritionWhatsappTestService.php');
function ok274(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
ok274(str_contains($delivery, "['pdf', 'images', 'both']"), 'Delivery must accept both.');
ok274(str_contains($delivery, "sendBothDirect"), 'Delivery must implement sendBothDirect.');
ok274(strpos($delivery, '$pdf = $this->sendPdfDirect') < strpos($delivery, '$images = $this->sendImagesDirect'), 'Both must send PDF before images.');
ok274(str_contains($delivery, "'both' => 'BOTH'"), 'Delivery response must identify BOTH.');
ok274(str_contains($controller, "in:pdf,images,both"), 'Controller must validate both.');
ok274(str_contains($testService, "['pdf', 'images', 'both']"), 'Test delivery must accept both.');
ok274(str_contains($testService, "sendBoth"), 'Test delivery must implement sendBoth.');
fwrite(STDOUT, "PASS: CRM Quick Nutrition both delivery contract\n");
