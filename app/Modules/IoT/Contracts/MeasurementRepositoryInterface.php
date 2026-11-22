<?php
namespace App\Modules\IoT\Contracts;
use App\Modules\IoT\Domain\Entities\Measurement;
interface MeasurementRepositoryInterface { public function exists(string $id):bool; public function findById(string $id):?Measurement; public function save(Measurement $measurement):void; public function attachContactOnce(string $measurementId,string $contactId,string $partnerId):void; }
