<?php
$root = dirname(__DIR__, 2);
$taskController = file_get_contents($root.'/app/Modules/CRM/Controllers/TaskController.php');
$taskRepo = file_get_contents($root.'/app/Modules/CRM/Contracts/TaskRepositoryInterface.php');
$sqlTaskRepo = file_get_contents($root.'/app/Modules/CRM/Repositories/SqlTaskRepository.php');
$collabController = file_get_contents($root.'/app/Modules/Campaigns/Controllers/CampaignCollaborationController.php');
$collabService = file_get_contents($root.'/app/Modules/Campaigns/Services/CampaignCollaborationService.php');
$errors=[];
if (!str_contains($taskController, "query('contact_id')")) $errors[]='TaskController does not read contact_id filter';
if (!str_contains($taskRepo, '?string $contactId=null')) $errors[]='TaskRepository contract lacks contactId';
if (!str_contains($sqlTaskRepo, "where('contact_id', \$contactId)")) $errors[]='SqlTaskRepository does not filter contact_id';
foreach (['X-Idempotency-Key','idempotencyKey','diet_idempotency'] as $needle) if (!str_contains($collabController.$collabService,$needle)) $errors[]="missing diet idempotency $needle";
if ($errors) { fwrite(STDERR, implode("\n", $errors)."\n"); exit(1); }
echo "MobileEventStabilityContractTest OK\n";
