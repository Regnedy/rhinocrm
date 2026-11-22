<?php
namespace App\Modules\Workflows\Actions;
use App\Modules\CRM\Domain\Entities\Contact;
use App\Modules\Workflows\Domain\Entities\WorkflowExecution;
interface WorkflowActionInterface{public function execute(array $config,WorkflowExecution $execution,Contact $contact):ActionResponse;}
