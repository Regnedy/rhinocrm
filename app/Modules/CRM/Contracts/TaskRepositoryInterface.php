<?php
namespace App\Modules\CRM\Contracts;
use App\Modules\CRM\Domain\Entities\Task;
interface TaskRepositoryInterface { public function find(string $id):?Task; public function save(Task $task):void; /** @return Task[] */ public function listForCurrentUser(?string $status=null,int $limit=100,?string $contactId=null):array; }
