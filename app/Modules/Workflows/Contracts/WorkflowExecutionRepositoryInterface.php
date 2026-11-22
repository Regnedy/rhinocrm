<?php
namespace App\Modules\Workflows\Contracts;
use App\Modules\Workflows\Domain\Entities\WorkflowExecution;
interface WorkflowExecutionRepositoryInterface{public function find(string $id):?WorkflowExecution;public function save(WorkflowExecution $execution):void;/** @return WorkflowExecution[] */public function findActiveForContact(string $contactId):array;}
