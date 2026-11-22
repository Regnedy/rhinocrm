<?php
namespace App\Modules\Workflows\Actions;
use App\Modules\CRM\Contracts\TaskRepositoryInterface;
use App\Modules\CRM\Contracts\TimelineActivityRepositoryInterface;
use App\Modules\CRM\Domain\Entities\Contact;
use App\Modules\CRM\Domain\Entities\Task;
use App\Modules\CRM\Domain\Entities\TimelineActivity;
use App\Modules\Workflows\Domain\Entities\WorkflowExecution;
use DateTimeImmutable;
final class CreateTaskAction implements WorkflowActionInterface
{
 public function __construct(private TaskRepositoryInterface $tasks,private TimelineActivityRepositoryInterface $timeline){}
 public function execute(array $config,WorkflowExecution $e,Contact $c):ActionResponse{$node=$e->getCurrentNodeId();$taskId='TSK_WF_'.strtoupper(substr(hash('sha256',$e->getId().'|'.$node),0,18));$hours=max(1,(int)($config['due_hours']??2));$task=new Task($taskId,$c->getPartnerId(),$c->getId(),$c->getAssignedAdvisorId()??'UNASSIGNED',(string)($config['task_type']??'FOLLOWUP'),(string)($config['title']??'Tarea automática'),'PENDING',(new DateTimeImmutable())->modify("+{$hours} hours"));$this->tasks->save($task);try{$this->timeline->save(new TimelineActivity('TML_'.$taskId,$c->getPartnerId(),$c->getId(),'TASK_CREATED','Tarea autogenerada: '.$task->getTitle(),['workflow_id'=>$e->getWorkflowId(),'execution_id'=>$e->getId()],'SYSTEM_WORKFLOW'));}catch(\Throwable){}return ActionResponse::proceed($config['next']??null,"Tarea {$taskId} creada");}
}
