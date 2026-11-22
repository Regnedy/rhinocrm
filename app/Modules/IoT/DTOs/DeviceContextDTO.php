<?php
namespace App\Modules\IoT\DTOs;
readonly class DeviceContextDTO
{
 public function __construct(public string $deviceId,public string $macAddress,public string $partnerId,public string $locationId){}
}
