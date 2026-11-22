<?php
$root = dirname(__DIR__, 2);
$routes = file_get_contents($root.'/routes/api_v1.php');
$campaign = file_get_contents($root.'/app/Modules/Campaigns/Services/CampaignService.php');
$diet = file_get_contents($root.'/app/Modules/Campaigns/Services/CampaignDietOwnershipService.php');
$mobile = file_get_contents($root.'/app/Modules/Mobile/Services/MobileMeasurementService.php');
$qr = file_get_contents($root.'/app/Modules/Campaigns/Services/CampaignQrJoinService.php');
$access = file_get_contents($root.'/app/Modules/Campaigns/Services/CampaignAccessService.php');
$errors=[];
foreach ([
    '/personal-devices',
    '/campaigns/{id}/available-devices',
    '/participants/{contactId}/history',
    '/kiosk-versions',
    '/kiosk/update/check',
    '/kiosk/update/installations',
] as $needle) if (!str_contains($routes, $needle)) $errors[]="missing route $needle";
foreach (['personal_devices','campaign_device_availability','campaign_assignment_history','kiosk_versions','kiosk_installations'] as $table) {
    if (!glob($root.'/database/migrations/*rhino_270*.php') || !str_contains(implode("\n", array_map('file_get_contents', glob($root.'/database/migrations/*rhino_270*.php'))), $table)) $errors[]="missing migration table $table";
}
if (preg_match("/'owner_user_id'\s*=>\s*\$this->context->userId/", $diet)) $errors[]='diet generation still changes prospect owner';
if (!str_contains($mobile, 'campaign_device_availability')) $errors[]='mobile measurement does not support shared event devices';
if (!str_contains($mobile, "participantUpdates['owner_user_id'] = \$this->context->userId")) $errors[]='event capture does not initialize prospect owner to capturer';
if (!str_contains($qr, 'personalDeviceIds')) $errors[]='QR accept does not accept personal devices';
if (!str_contains($campaign, "'display_status'")) $errors[]='campaign API lacks FINISHED display status';
if (!str_contains($campaign, "'device_used'")) $errors[]='campaign participant list lacks device used and owner';
if (!str_contains($campaign, "PersonalDeviceService::class)->releaseCampaign(\$campaignId)")) $errors[]='campaign close does not release available devices';
if (!str_contains(file_get_contents($root.'/app/Modules/Campaigns/Services/CampaignCollaborationService.php'), "status !== 'CLOSED'")) $errors[]='post-event access boundary missing';
if ($errors) { fwrite(STDERR, implode("\n", $errors)."\n"); exit(1); }

$crmUi = file_get_contents($root.'/public/assets/rhino-crm.js');
foreach ([
    "['kiosk-versions'" => 'SUPER ADMIN navigation must expose Kiosko versions',
    'function renderKioskVersions' => 'SUPER ADMIN Kiosko versions renderer missing',
    '/admin/kiosk-versions' => 'Kiosko version admin API must be wired into CRM UI',
    'name="apk"' => 'Kiosko version form must accept APK upload',
    "FINISHED:'Finalizado'" => 'CRM must present FINISHED as Finalizado',
    'display_status||c.status' => 'Event cards must prefer 2.7.0 display status',
    '<section class="card card-pad span-6">' => 'Kiosko version cards must use the existing responsive 12-column grid',
] as $needle => $message) { if (!str_contains($crmUi, $needle)) $errors[]=$message; }
if ($errors) { fwrite(STDERR, implode("\n", $errors)."\n"); exit(1); }

echo "Rhino270ContractTest OK\n";
