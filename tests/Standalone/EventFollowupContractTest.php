<?php
$root = dirname(__DIR__, 2);
$migrationFiles = glob($root.'/database/migrations/*event_followup_state*.php') ?: [];
$service = file_get_contents($root.'/app/Modules/Campaigns/Services/CampaignCollaborationService.php');
$required = ['contact_started_at','commercial_status','markContactStarted','updateCommercialStatus','assignOwner'];
$errors = [];
if ($migrationFiles === []) $errors[] = 'missing followup-state migration';
foreach ($required as $needle) if (!str_contains($service, $needle) && ($migrationFiles === [] || !str_contains(file_get_contents($migrationFiles[0]), $needle))) $errors[] = "missing $needle";
if ($errors) { fwrite(STDERR, implode("\n", $errors)."\n"); exit(1); }
echo "EventFollowupContractTest OK\n";
