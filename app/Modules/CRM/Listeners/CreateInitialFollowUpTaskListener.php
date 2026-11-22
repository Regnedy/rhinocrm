<?php
namespace App\Modules\CRM\Listeners;
use App\Modules\Auth\Services\TenantContextRunner;
use App\Modules\CRM\Events\AdvisorAssignedEvent;
use App\Modules\CRM\Services\InitialFollowUpService;
use Illuminate\Contracts\Queue\ShouldQueue;
final class CreateInitialFollowUpTaskListener implements ShouldQueue
{
 public int $tries=3;public function __construct(private TenantContextRunner $runner){}
 public function handle(AdvisorAssignedEvent $e):void{$this->runner->runAsSystem($e->partnerId,fn()=>app(InitialFollowUpService::class)->createFor($e));}
}
