<?php
namespace App\Modules\CRM\Listeners;
use App\Modules\Auth\Services\TenantContextRunner;
use App\Modules\CRM\Services\PromoteCustomerService;
use App\Modules\Sales\Events\SaleCompletedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
final class PromoteContactToCustomer implements ShouldQueue
{public int $tries=3;public function __construct(private TenantContextRunner $runner){}public function handle(SaleCompletedEvent $e):void{$this->runner->runAsSystem($e->partnerId,fn()=>app(PromoteCustomerService::class)->handle($e));}}
