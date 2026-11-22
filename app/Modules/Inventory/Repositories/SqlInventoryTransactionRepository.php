<?php

namespace App\Modules\Inventory\Repositories;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Inventory\Contracts\InventoryTransactionRepositoryInterface;
use App\Modules\Inventory\Domain\Entities\InventoryTransaction;
use App\Support\Database\GuardsTenant;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;

final class SqlInventoryTransactionRepository implements InventoryTransactionRepositoryInterface
{
    use GuardsTenant;

    public function __construct(private readonly UserContextDTO $context) {}

    public function save(InventoryTransaction $transaction): void
    {
        $this->guardTenant($transaction->partnerId);
        DB::table('inventory_transactions')->insertOrIgnore([
            'id' => $transaction->id,
            'partner_id' => $transaction->partnerId,
            'location_id' => $transaction->locationId,
            'product_id' => $transaction->productId,
            'transaction_type' => $transaction->transactionType,
            'quantity_change' => $transaction->quantityChange,
            'reference_id' => $transaction->referenceId,
            'created_at' => SqlValue::timestamp($transaction->createdAt),
        ]);
    }
}
