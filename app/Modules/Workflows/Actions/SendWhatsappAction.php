<?php
namespace App\Modules\Workflows\Actions;
use App\Modules\CRM\Domain\Entities\Contact;
use App\Modules\Integrations\Whatsapp\Contracts\WhatsappMessageRepositoryInterface;
use App\Modules\Integrations\Whatsapp\Contracts\WhatsappProviderInterface;
use App\Modules\Integrations\Whatsapp\Domain\Entities\WhatsappMessage;
use App\Modules\Workflows\Domain\Entities\WorkflowExecution;
final class SendWhatsappAction implements WorkflowActionInterface
{
 public function __construct(private WhatsappProviderInterface $provider,private WhatsappMessageRepositoryInterface $messages){}
 public function execute(array $config,WorkflowExecution $e,Contact $c):ActionResponse{$template=(string)($config['template_name']??throw new \InvalidArgumentException('template_name obligatorio'));$id='WAM_'.strtoupper(substr(hash('sha256',$e->getId().'|'.$e->getCurrentNodeId()),0,20));$existing=$this->messages->find($id);if($existing&&in_array($existing->getStatus(),['SENT','DELIVERED','READ'],true))return ActionResponse::proceed($config['next']??null,'WhatsApp ya enviado de forma idempotente.');$vars=$this->resolve($c,$config['variables']??[]);$message=$existing??new WhatsappMessage($id,$c->getPartnerId(),$c->getId(),$c->getWhatsapp(),$template,$vars,'QUEUED');$message->markProcessing();$this->messages->save($message);try{$external=$this->provider->sendTemplate($message->getToPhoneNumber(),$template,$vars,(string)($config['language']??'es_MX'),$c->getPartnerId());$message->markAsSent($external);$this->messages->save($message);return ActionResponse::proceed($config['next']??null,"WhatsApp enviado: {$external}");}catch(\Throwable $x){$message->updateStatus('FAILED',mb_substr($x->getMessage(),0,500));$this->messages->save($message);throw $x;}}
 private function resolve(Contact $c,array $map):array{$out=[];foreach($map as $key=>$path)$out[$key]=match($path){'contact.name'=>$c->getName(),'contact.metrics.peso_kg'=>$c->getMetricsSummary()['peso_kg']??'N/A','contact.metrics.imc'=>$c->getMetricsSummary()['imc']??'N/A','contact.metrics.grasa_porcentaje'=>$c->getMetricsSummary()['grasa_porcentaje']??'N/A',default=>'Dato'};return$out;}
}
