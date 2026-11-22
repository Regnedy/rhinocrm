<?php

namespace App\Modules\Sales\Repositories;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Sales\Contracts\SaleRepositoryInterface;
use App\Modules\Sales\Domain\Entities\Sale;
use App\Modules\Sales\Domain\Entities\SaleItem;
use App\Support\Database\GuardsTenant;
use App\Support\Database\SqlValue;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class SqlSaleRepository implements SaleRepositoryInterface
{
    use GuardsTenant;

    public function __construct(private readonly UserContextDTO $context) {}

    public function find(string $id): ?Sale
    {
        $row = DB::table('sales')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }
        $this->guardTenant((string) $row->partner_id);
        return new Sale(
            (string) $row->id,
            (string) $row->partner_id,
            (string) $row->contact_id,
            (string) $row->advisor_id,
            (string) $row->location_id,
            (string) $row->sale_type,
            (string) $row->status,
            (string) $row->payment_method,
            (int) $row->total_revenue_minor,
            (int) $row->total_profit_minor,
            (string) $row->currency_snapshot,
            $row->origin_device_id !== null ? (string) $row->origin_device_id : null,
            (string) $row->idempotency_key,
            SqlValue::date($row->closed_at, new DateTimeImmutable()) ?? new DateTimeImmutable(),
            SqlValue::date($row->refunded_at),
        );
    }

    public function findItems(string $saleId): array
    {
        if ($this->find($saleId) === null) {
            return [];
        }
        return DB::table('sale_items')->where('sale_id', $saleId)->get()
            ->map(fn (object $row): SaleItem => new SaleItem(
                (string) $row->id,
                (string) $row->sale_id,
                (string) $row->product_id,
                (int) $row->quantity,
                (string) $row->product_name_snapshot,
                (int) $row->unit_price_minor_snapshot,
                (int) $row->unit_cost_minor_snapshot,
                (int) $row->subtotal_revenue_minor,
                (int) $row->subtotal_profit_minor,
            ))->all();
    }
}
