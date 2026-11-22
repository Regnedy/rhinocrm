<?php
namespace App\Modules\Workflows\Contracts;
use App\Modules\Workflows\Domain\Entities\WorkflowExecutionLog;
interface WorkflowLogRepositoryInterface{public function save(WorkflowExecutionLog $log):void;}
