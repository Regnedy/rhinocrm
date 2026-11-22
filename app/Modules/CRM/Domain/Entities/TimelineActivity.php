<?php
namespace App\Modules\CRM\Domain\Entities;
use DateTimeImmutable;
readonly class TimelineActivity
{
 public function __construct(public string $id,public string $partnerId,public string $contactId,public string $type,public string $description,public ?array $metadata,public ?string $actorId,public DateTimeImmutable $createdAt=new DateTimeImmutable()){}
}
