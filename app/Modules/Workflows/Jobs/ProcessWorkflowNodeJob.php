<?php
namespace App\Modules\Workflows\Jobs;
use App\Modules\Auth\Services\TenantContextRunner;
use App\Modules\CRM\Contracts\ContactRepositoryInterface;
use App\Modules\Workflows\Contracts\WorkflowExecutionRepositoryInterface;
use App\Modules\Workflows\Services\WorkflowEngineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
final class ProcessWorkflowNodeJob implements ShouldQueue
{use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;public int $tries=4;public function backoff():array{return[60,300,900];}public function __construct(public string $executionId,public string $contactId,public string $partnerId){}public function handle(TenantContextRunner $runner):void{$runner->runAsSystem($this->partnerId,function(){ $e=app(WorkflowExecutionRepositoryInterface::class)->find($this->executionId);$c=app(ContactRepositoryInterface::class)->find($this->contactId);if(!$e||!$c)return;app(WorkflowEngineService::class)->processExecution($e,$c);});}}
