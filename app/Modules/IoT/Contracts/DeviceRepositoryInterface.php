<?php
namespace App\Modules\IoT\Contracts;
use App\Modules\IoT\Domain\Entities\Device;
interface DeviceRepositoryInterface { public function findByMac(string $macAddress):?Device; public function find(string $id):?Device; }
