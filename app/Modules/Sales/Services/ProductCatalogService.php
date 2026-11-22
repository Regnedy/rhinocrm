<?php

namespace App\Modules\Sales\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Sales\Contracts\ProductRepositoryInterface;
use App\Modules\Sales\Domain\Entities\Product;
use Illuminate\Support\Str;

final class ProductCatalogService
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly UserContextDTO $context,
    ) {}

    public function create(array $data): Product
    {
        $partnerId = $this->context->partnerId ?? throw new \RuntimeException('Contexto sin partner.', 403);

        $product = new Product(
            id: 'PRD_'.strtoupper(Str::random(12)),
            partnerId: $partnerId,
            sku: strtoupper(trim((string) $data['sku'])),
            name: trim((string) $data['name']),
            category: trim((string) $data['category']),
            basePriceMinor: (int) $data['base_price_minor'],
            baseCostMinor: (int) $data['base_cost_minor'],
            currency: strtoupper((string) $data['currency']),
            active: (bool) ($data['is_active'] ?? true),
        );

        $this->products->save($product);

        return $product;
    }
}
