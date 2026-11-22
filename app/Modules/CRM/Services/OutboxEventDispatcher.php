<?php
namespace App\Modules\CRM\Services;
use App\Modules\CRM\Events\AdvisorAssignedEvent;
use App\Modules\CRM\Events\ContactMeasurementProcessedEvent;
use App\Modules\CRM\Events\ProspectStageChangedEvent;
use App\Modules\Integrations\Whatsapp\Events\WhatsappMessageStatusChangedEvent;
use App\Modules\IoT\Events\MeasurementReceivedEvent;
use App\Modules\Sales\Events\SaleCompletedEvent;
use App\Modules\Sales\Events\SaleRefundedEvent;
final class OutboxEventDispatcher
{
 public function dispatch(array $data):void{$p=is_array($data['payload']??null)?$data['payload']:json_decode((string)($data['payload']??'{}'),true,512,JSON_THROW_ON_ERROR);$id=(string)$data['id'];$partner=(string)$data['partner_id'];$v=(int)($data['event_version']??1);match($data['event_type']){
  'MEASUREMENT_RECEIVED'=>event(new MeasurementReceivedEvent($id,$partner,(string)$p['measurement_id'],$v)),
  'CONTACT_MEASUREMENT_PROCESSED'=>event(new ContactMeasurementProcessedEvent($id,$partner,(string)$p['contact_id'],(string)$p['measurement_id'],(bool)$p['is_new_contact'],$v)),
  'ADVISOR_ASSIGNED'=>event(new AdvisorAssignedEvent($id,$partner,(string)$p['contact_id'],(string)$p['advisor_id'],(string)$p['location_id'],$v)),
  'PROSPECT_STAGE_CHANGED'=>event(new ProspectStageChangedEvent($id,$partner,(string)$p['contact_id'],(string)$p['previous_stage'],(string)$p['new_stage'],$p['actor_id']??null,$v)),
  'WHATSAPP_STATUS_CHANGED'=>event(new WhatsappMessageStatusChangedEvent($id,$partner,(string)$p['message_id'],(string)$p['contact_id'],(string)$p['status'],(string)$p['previous_status'],(string)$p['template_name'],$p['error_message']??null,$v)),
  'SALE_COMPLETED'=>event(new SaleCompletedEvent($id,$partner,(string)$p['sale_id'],(string)$p['contact_id'],(string)$p['advisor_id'],$p['origin_device_id']??null,(string)$p['sale_type'],(int)$p['total_revenue_minor'],(int)$p['total_profit_minor'],(string)$p['currency'],(string)$p['closed_at'],(array)$p['items'],$p['origin_campaign_id']??null,$v)),
  'SALE_REFUNDED'=>event(new SaleRefundedEvent($id,$partner,(string)$p['sale_id'],(string)$p['contact_id'],(string)$p['advisor_id'],$p['origin_device_id']??null,(int)$p['total_revenue_minor'],(int)$p['total_profit_minor'],(string)$p['currency'],(string)$p['refunded_at'],(array)$p['items'],$v)),
  default=>throw new \RuntimeException('Tipo de evento outbox desconocido: '.$data['event_type'])};}
}
