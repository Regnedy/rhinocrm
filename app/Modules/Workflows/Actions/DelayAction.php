<?php
namespace App\Modules\Workflows\Actions;
use App\Modules\CRM\Domain\Entities\Contact;
use App\Modules\Workflows\Domain\Entities\WorkflowExecution;
use DateInterval;
use DateTimeImmutable;
final class DelayAction implements WorkflowActionInterface
{public function execute(array $config,WorkflowExecution $execution,Contact $contact):ActionResponse{$amount=max(1,(int)($config['amount']??24));$spec=match($config['unit']??'hours'){'minutes'=>"PT{$amount}M",'days'=>"P{$amount}D",default=>"PT{$amount}H"};$resume=(new DateTimeImmutable())->add(new DateInterval($spec));return ActionResponse::pause((string)($config['next']??''),$resume,"Pausa hasta {$resume->format(DATE_ATOM)}");}}
