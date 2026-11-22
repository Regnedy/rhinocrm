<?php

namespace App\Modules\Finance\Repositories;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Finance\Contracts\LedgerRepositoryInterface;
use App\Modules\Finance\Domain\Entities\FinancialTransaction;
use App\Support\Database\GuardsTenant;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;

final class SqlLedgerRepository implements LedgerRepositoryInterface
{
    use GuardsTenant;

    public function __construct(private readonly UserContextDTO $context) {}

    public function save(FinancialTransaction $transaction): void
    {
        $this->guardTenant($transaction->partnerId);
        DB::table('financial_transactions')->insertOrIgnore([
            'id' => $transaction->id,
            'partner_id' => $transaction->partnerId,
            'sale_id' => $transaction->saleId,
            'type' => $transaction->type,
            'amount_minor' => $transaction->amountMinor,
            'profit_minor' => $transaction->profitMinor,
            'currency' => $transaction->currency,
            'created_at' => SqlValue::timestamp($transaction->createdAt),
        ]);
    }
}
