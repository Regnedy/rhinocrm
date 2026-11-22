<?php
$root = dirname(__DIR__, 2);
$routes = file_get_contents($root.'/routes/api_v1.php');
$controller = file_get_contents($root.'/app/Modules/Campaigns/Controllers/CampaignCollaborationController.php');
$service = file_get_contents($root.'/app/Modules/Campaigns/Services/CampaignCollaborationService.php');
$errors=[];
foreach (['contact-started','commercial-status','assignee','/campaigns/{id}/collaborators','markContactStarted','updateCommercialStatus','assignOwner','collaborators','can_assign','plans/{planId}/send-whatsapp','sendPlanWhatsapp'] as $needle) {
    if (!str_contains($routes.$controller.$service, $needle)) $errors[]="missing $needle";
}
if (!preg_match('/private function assertParticipantVisible.*?private function participantArray/s', $service, $visibility) || !str_contains($visibility[0] ?? '', 'assigned_advisor_id')) $errors[]='closed-event visibility must include assigned advisor';
if (!str_contains($service, '$respondedChanged = DB::transaction') || !str_contains($service, 'if ($respondedChanged) $this->activity->record')) $errors[]='manual responded action must be idempotent in the event timeline';
if ($errors) { fwrite(STDERR, implode("\n", $errors)."\n"); exit(1); }
echo "EventParticipantActionsContractTest OK\n";
