<?php
namespace App\Modules\Integrations\Whatsapp\Contracts;
use App\Modules\Integrations\Whatsapp\Domain\Entities\WhatsappMessage;
interface WhatsappMessageRepositoryInterface{public function find(string $id):?WhatsappMessage;public function findByExternalId(string $externalId):?WhatsappMessage;public function save(WhatsappMessage $message):void;}
