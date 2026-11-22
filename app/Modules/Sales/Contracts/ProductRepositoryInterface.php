<?php

namespace App\Modules\Sales\Contracts;

use App\Modules\Sales\Domain\Entities\Product;

interface ProductRepositoryInterface
{
    public function find(string $id): ?Product;

    /** @return array<string, Product> */
    public function findMany(array $ids): array;

    /** @return Product[] */
    public function list(int $limit = 100, bool $onlyActive = false): array;

    public function save(Product $product): void;
}
