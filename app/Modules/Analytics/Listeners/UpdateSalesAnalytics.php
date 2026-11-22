<?php
namespace App\Modules\Analytics\Listeners;
use App\Modules\Analytics\Services\SalesAnalyticsProjector;
use App\Modules\Auth\Services\TenantContextRunner;
use App\Modules\Sales\Events\SaleCompletedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
final class UpdateSalesAnalytics implements ShouldQueue
{public int $tries=3;public function __construct(private TenantContextRunner $runner){}public function handle(SaleCompletedEvent $e):void{$this->runner->runAsSystem($e->partnerId,fn()=>app(SalesAnalyticsProjector::class)->project($e));}}
