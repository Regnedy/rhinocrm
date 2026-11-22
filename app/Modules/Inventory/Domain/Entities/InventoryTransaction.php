<?php
namespace App\Modules\Inventory\Domain\Entities;
use DateTimeImmutable;
readonly class InventoryTransaction
{public function __construct(public string $id,public string $partnerId,public string $locationId,public string $productId,public string $transactionType,public int $quantityChange,public ?string $referenceId=null,public DateTimeImmutable $createdAt=new DateTimeImmutable()){}}
