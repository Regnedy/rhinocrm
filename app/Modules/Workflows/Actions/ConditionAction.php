<?php
namespace App\Modules\Workflows\Actions;
use App\Modules\CRM\Domain\Entities\Contact;
use App\Modules\Workflows\Domain\Entities\WorkflowExecution;
final class ConditionAction implements WorkflowActionInterface
{
 public function execute(array $config,WorkflowExecution $execution,Contact $contact):ActionResponse{$actual=$this->value($contact,(string)($config['field']??''));$expected=$config['value']??null;$match=match($config['operator']??'eq'){'neq'=>$actual!=$expected,'gt'=>is_numeric($actual)&&$actual>$expected,'gte'=>is_numeric($actual)&&$actual>=$expected,'lt'=>is_numeric($actual)&&$actual<$expected,'lte'=>is_numeric($actual)&&$actual<=$expected,'in'=>in_array($actual,(array)$expected,true),default=>$actual==$expected};$next=(string)($match?($config['on_true']??''):($config['on_false']??''));return ActionResponse::proceed($next,'Condición '.($match?'verdadera':'falsa'));}
 private function value(Contact $c,string $field):mixed{return match($field){'contact.lifecycle_stage'=>$c->getLifecycleStage(),'contact.sales_stage'=>$c->getSalesStage(),'contact.metrics.imc'=>$c->getMetricsSummary()['imc']??null,'contact.metrics.grasa_porcentaje'=>$c->getMetricsSummary()['grasa_porcentaje']??null,'contact.total_measurements'=>$c->getTotalMeasurements(),default=>null};}
}
