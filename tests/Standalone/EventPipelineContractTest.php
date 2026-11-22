<?php
$root = dirname(__DIR__, 2);
$routes = file_get_contents($root.'/routes/api_v1.php');
$controller = file_get_contents($root.'/app/Modules/Campaigns/Controllers/CampaignController.php');
$service = file_get_contents($root.'/app/Modules/Campaigns/Services/CampaignService.php');
$errors = [];
foreach (['/campaigns/{id}/pipeline','function pipeline','event_state','WITH_WHATSAPP','PLAN_SENT','sale_summary'] as $needle) {
    if (!str_contains($routes.$controller.$service, $needle)) $errors[] = "missing $needle";
}
if (str_contains($service, "DB::table('sales')->where('origin_campaign_id', \$row->campaign_id)")) $errors[] = 'legacy N+1 sale query still present';
if (!preg_match('/case \'PURCHASE_COMPLETED\':\s*\$query->where\(function .*?sale_summary\.sale_count/s', $service)) $errors[] = 'purchase filter must include historical completed sales';
if (!preg_match('/\$commercialStatus === \'PURCHASE_COMPLETED\'.*?sale_summary\.sale_count/s', $service)) $errors[] = 'commercial_status purchase query must include historical completed sales';
if (str_contains($service, '$q->whereNot($verified)')) $errors[] = 'WITHOUT_WHATSAPP relies on whereNot closure compatibility';
if (!preg_match('/case \'WITHOUT_WHATSAPP\':.*?measurements_count.*?case \'WITH_WHATSAPP\':.*?measurements_count.*?case \'PLAN_PENDING\':.*?measurements_count/s', $service)) $errors[] = 'quick opportunity filters must be restricted to event measurements';
if ($errors) { fwrite(STDERR, implode("\n", $errors)."\n"); exit(1); }
echo "EventPipelineContractTest OK\n";
