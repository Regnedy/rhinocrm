<?php
namespace App\Modules\Workflows\Services;
use App\Modules\Workflows\Actions\ConditionAction;
use App\Modules\Workflows\Actions\CreateTaskAction;
use App\Modules\Workflows\Actions\DelayAction;
use App\Modules\Workflows\Actions\SendWhatsappAction;
use App\Modules\Workflows\Actions\WorkflowActionInterface;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
final class ActionFactory
{
 private array $registry=['DELAY'=>DelayAction::class,'CREATE_TASK'=>CreateTaskAction::class,'SEND_WHATSAPP'=>SendWhatsappAction::class,'CONDITION'=>ConditionAction::class];
 public function __construct(private Container $container){}
 public function registerAction(string $type,string $class):void{if(!is_subclass_of($class,WorkflowActionInterface::class))throw new InvalidArgumentException("{$class} debe implementar WorkflowActionInterface");$this->registry[strtoupper($type)]=$class;}
 public function make(string $type):WorkflowActionInterface{$class=$this->registry[strtoupper($type)]??throw new InvalidArgumentException("Nodo no registrado: {$type}");return $this->container->make($class);}
}
