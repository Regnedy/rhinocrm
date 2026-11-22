<?php
namespace App\Modules\Finance\Domain\Entities;
use DateTimeImmutable;
readonly class FinancialTransaction
{public function __construct(public string $id,public string $partnerId,public string $saleId,public string $type,public int $amountMinor,public int $profitMinor,public string $currency,public DateTimeImmutable $createdAt=new DateTimeImmutable()){}}
