<?php
namespace App\Modules\Analytics\Listeners;
use App\Modules\Analytics\Services\FunnelAnalyticsProjector;
use App\Modules\Auth\Services\TenantContextRunner;
use App\Modules\CRM\Events\ProspectStageChangedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
final class UpdateFunnelAnalytics implements ShouldQueue
{public int $tries=3;public function __construct(private TenantContextRunner $runner){}public function handle(ProspectStageChangedEvent $e):void{$this->runner->runAsSystem($e->partnerId,fn()=>app(FunnelAnalyticsProjector::class)->project($e));}}
