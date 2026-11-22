<?php
namespace App\Modules\CRM\Contracts;
use App\Modules\CRM\Domain\Entities\TimelineActivity;
interface TimelineActivityRepositoryInterface { public function save(TimelineActivity $activity):void; /** @return TimelineActivity[] */ public function listForContact(string $contactId,int $limit=100):array; }
