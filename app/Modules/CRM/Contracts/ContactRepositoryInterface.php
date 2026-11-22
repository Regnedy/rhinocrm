<?php
namespace App\Modules\CRM\Contracts;
use App\Modules\CRM\Domain\Entities\Contact;
interface ContactRepositoryInterface
{
 public function find(string $id):?Contact;
 public function findByWhatsappAndPartner(string $whatsapp,string $partnerId):?Contact;
 public function save(Contact $contact):void;
 /** @return Contact[] */ public function getPipeline(?string $salesStage=null,?string $assignedAdvisorId=null,int $limit=100):array;
}
