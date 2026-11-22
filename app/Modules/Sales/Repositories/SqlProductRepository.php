<?php

namespace App\Modules\Sales\Repositories;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Sales\Contracts\ProductRepositoryInterface;
use App\Modules\Sales\Domain\Entities\Product;
use App\Support\Database\GuardsTenant;
use App\Support\Database\SqlValue;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class SqlProductRepository implements ProductRepositoryInterface
{
    use GuardsTenant;

    public function __construct(private readonly UserContextDTO $context) {}

    public function find(string $id): ?Product
    {
        $row = DB::table('products')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }
        $this->guardTenant((string) $row->partner_id);
        return $this->map($row);
    }

    public function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        if ($ids === []) {
            return [];
        }
        $partnerId = $this->context->partnerId ?? throw new \RuntimeException('Contexto sin partner.', 403);
        $rows = DB::table('products')->where('partner_id', $partnerId)->whereIn('id', $ids)->get();
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->id] = $this->map($row);
        }
        return $result;
    }

    public function list(int $limit = 100, bool $onlyActive = false): array
    {
        $partnerId = $this->context->partnerId ?? throw new \RuntimeException('Contexto sin partner.', 403);
        $query = DB::table('products')->where('partner_id', $partnerId)->whereNull('deleted_at');
        if ($onlyActive) {
            $query->where('active', true);
        }
        return $query->orderBy('name')->limit(min(max($limit, 1), 200))->get()
            ->map(fn (object $row): Product => $this->map($row))->all();
    }

    public function save(Product $product): void
    {
        $this->guardTenant($product->getPartnerId());
        DB::table('products')->updateOrInsert(['id' => $product->getId()], [
            'partner_id' => $product->getPartnerId(),
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'category' => $product->getCategory(),
            'base_price_minor' => $product->getBasePriceMinor(),
            'base_cost_minor' => $product->getBaseCostMinor(),
            'currency' => $product->getCurrency(),
            'active' => $product->isActive(),
            'created_at' => SqlValue::timestamp($product->getCreatedAt()),
            'updated_at' => now(),
            'deleted_at' => $product->getDeletedAt()?->format('Y-m-d H:i:s'),
        ]);
    }

    private function map(object $row): Product
    {
        return new Product(
            (string) $row->id,
            (string) $row->partner_id,
            (string) $row->sku,
            (string) $row->name,
            (string) ($row->category ?? ''),
            (int) $row->base_price_minor,
            (int) $row->base_cost_minor,
            (string) $row->currency,
            (bool) $row->active,
            SqlValue::date($row->created_at, new DateTimeImmutable()) ?? new DateTimeImmutable(),
            SqlValue::date($row->deleted_at),
        );
    }
}
