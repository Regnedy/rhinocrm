<?php
namespace App\Modules\IoT\Domain\Entities;
use DateTimeImmutable;
final class Device
{
 public function __construct(private string $id,private string $macAddress,private string $partnerId,private string $locationId,private string $deviceToken,private string $status,private int $totalMeasurements=0,private DateTimeImmutable $createdAt=new DateTimeImmutable(),private ?DateTimeImmutable $deletedAt=null){}
 public function getId():string{return $this->id;} public function getMacAddress():string{return $this->macAddress;} public function getPartnerId():string{return $this->partnerId;} public function getLocationId():string{return $this->locationId;} public function getDeviceToken():string{return $this->deviceToken;} public function getStatus():string{return $this->status;} public function getTotalMeasurements():int{return $this->totalMeasurements;} public function getCreatedAt():DateTimeImmutable{return $this->createdAt;} public function getDeletedAt():?DateTimeImmutable{return $this->deletedAt;}
 public function isActive():bool{return $this->status==='ACTIVE'&&$this->deletedAt===null;}
}
