<?php
namespace App\Modules\Workflows\Domain\Entities;
use DateTimeImmutable;
final class Workflow
{
 public function __construct(private string $id,private string $partnerId,private string $name,private int $version,private string $triggerEvent,private array $triggerConditions,private array $nodes,private string $entryNodeId,private bool $cancelOnStageChange,private bool $isActive,private DateTimeImmutable $createdAt=new DateTimeImmutable()){}
 public function getId():string{return $this->id;}public function getPartnerId():string{return $this->partnerId;}public function getName():string{return $this->name;}public function getVersion():int{return $this->version;}public function getTriggerEvent():string{return $this->triggerEvent;}public function getTriggerConditions():array{return $this->triggerConditions;}public function getNodes():array{return $this->nodes;}public function getEntryNodeId():string{return $this->entryNodeId;}public function isCancelableOnStageChange():bool{return $this->cancelOnStageChange;}public function isActive():bool{return $this->isActive;}public function getCreatedAt():DateTimeImmutable{return $this->createdAt;}
}
