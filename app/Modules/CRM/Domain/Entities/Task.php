<?php
namespace App\Modules\CRM\Domain\Entities;
use DateTimeImmutable;
final class Task
{
 public const STATUSES=['PENDING','COMPLETED','CANCELED'];
 public function __construct(private string $id,private string $partnerId,private string $contactId,private string $assignedUserId,private string $type,private string $title,private string $status,private DateTimeImmutable $dueDate,private ?string $outcome=null,private DateTimeImmutable $createdAt=new DateTimeImmutable(),private ?DateTimeImmutable $completedAt=null,private ?DateTimeImmutable $canceledAt=null){if(!in_array($status,self::STATUSES,true))throw new \InvalidArgumentException('Estado de tarea inválido.');}
 public function getId():string{return $this->id;}public function getPartnerId():string{return $this->partnerId;}public function getContactId():string{return $this->contactId;}public function getAssignedUserId():string{return $this->assignedUserId;}public function getType():string{return $this->type;}public function getTitle():string{return $this->title;}public function getStatus():string{return $this->status;}public function getDueDate():DateTimeImmutable{return $this->dueDate;}public function getOutcome():?string{return $this->outcome;}public function getCreatedAt():DateTimeImmutable{return $this->createdAt;}public function getCompletedAt():?DateTimeImmutable{return $this->completedAt;}public function getCanceledAt():?DateTimeImmutable{return $this->canceledAt;}public function isOverdue(?DateTimeImmutable $now=null):bool{return $this->status==='PENDING'&&$this->dueDate<($now??new DateTimeImmutable());}
 public function complete(string $outcome='DONE'):void{if($this->status!=='PENDING')throw new \DomainException('Solo una tarea pendiente puede completarse.');$this->status='COMPLETED';$this->outcome=$outcome;$this->completedAt=new DateTimeImmutable();}
 public function cancel(?string $reason=null):void{if($this->status!=='PENDING')throw new \DomainException('Solo una tarea pendiente puede cancelarse.');$this->status='CANCELED';$this->outcome=$reason??'Canceled by user';$this->canceledAt=new DateTimeImmutable();}
 public function reschedule(DateTimeImmutable $newDate):void{if($this->status!=='PENDING')throw new \DomainException('Solo una tarea pendiente puede reagendarse.');$this->dueDate=$newDate;$this->outcome='Rescheduled';}
}
