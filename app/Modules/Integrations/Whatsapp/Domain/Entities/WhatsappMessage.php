<?php
namespace App\Modules\Integrations\Whatsapp\Domain\Entities;
use DateTimeImmutable;
final class WhatsappMessage
{
 public function __construct(private string $id,private string $partnerId,private string $contactId,private string $toPhoneNumber,private string $templateName,private array $variables,private string $status,private ?string $externalId=null,private ?string $errorMessage=null,private DateTimeImmutable $createdAt=new DateTimeImmutable(),private ?DateTimeImmutable $updatedAt=null){}
 public function getId():string{return $this->id;}public function getPartnerId():string{return $this->partnerId;}public function getContactId():string{return $this->contactId;}public function getToPhoneNumber():string{return $this->toPhoneNumber;}public function getTemplateName():string{return $this->templateName;}public function getVariables():array{return $this->variables;}public function getStatus():string{return $this->status;}public function getExternalId():?string{return $this->externalId;}public function getErrorMessage():?string{return $this->errorMessage;}public function getCreatedAt():DateTimeImmutable{return $this->createdAt;}public function getUpdatedAt():?DateTimeImmutable{return $this->updatedAt;}
 public function markProcessing():void{$this->status='PROCESSING';$this->updatedAt=new DateTimeImmutable();}public function markAsSent(string $id):void{$this->status='SENT';$this->externalId=$id;$this->errorMessage=null;$this->updatedAt=new DateTimeImmutable();}public function updateStatus(string $status,?string $error=null):void{$this->status=strtoupper($status);$this->errorMessage=$error;$this->updatedAt=new DateTimeImmutable();}
}
