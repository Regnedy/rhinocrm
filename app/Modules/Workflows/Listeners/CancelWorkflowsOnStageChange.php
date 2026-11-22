<?php
namespace App\Modules\Workflows\Listeners;
use App\Modules\Auth\Services\TenantContextRunner;
use App\Modules\CRM\Events\ProspectStageChangedEvent;
use App\Modules\Workflows\Contracts\WorkflowExecutionRepositoryInterface;
use App\Modules\Workflows\Contracts\WorkflowLogRepositoryInterface;
use App\Modules\Workflows\Contracts\WorkflowRepositoryInterface;
use App\Modules\Workflows\Domain\Entities\WorkflowExecutionLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;
final class CancelWorkflowsOnStageChange implements ShouldQueue
{public function __construct(private TenantContextRunner $runner){}public function handle(ProspectStageChangedEvent $e):void{$this->runner->runAsSystem($e->partnerId,function()use($e){$execs=app(WorkflowExecutionRepositoryInterface::class);$defs=app(WorkflowRepositoryInterface::class);$logs=app(WorkflowLogRepositoryInterface::class);foreach($execs->findActiveForContact($e->contactId) as $x){$w=$defs->find($x->getWorkflowId());if($w&&$w->isCancelableOnStageChange()){$x->cancel();$execs->save($x);$logs->save(new WorkflowExecutionLog('WFL_'.strtoupper(Str::random(12)),$x->getId(),$e->partnerId,$x->getCurrentNodeId(),'ESCAPE_HATCH','SUCCESS',"Cancelado por cambio a {$e->newStage}."));}}});}}
