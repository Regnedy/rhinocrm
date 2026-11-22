<?php
namespace App\Modules\IoT\Domain\Entities;
use DateTimeImmutable;
readonly class Measurement
{
 public function __construct(public string $id,public string $deviceId,public string $partnerId,public string $locationId,public ?string $contactId,public array $rawBiometrics,public array $calculatedBiometrics,public DateTimeImmutable $timestampLocal,public DateTimeImmutable $receivedAt=new DateTimeImmutable()){}
}
