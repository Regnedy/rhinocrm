<?php

namespace App\Modules\Sales\Controllers;

use App\Modules\Sales\Contracts\ProductRepositoryInterface;
use App\Modules\Sales\Requests\StoreProductRequest;
use App\Modules\Sales\Services\ProductCatalogService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductController
{
    use ApiResponse;

    public function index(
        Request $request,
        ProductRepositoryInterface $products,
    ): JsonResponse {
        $items = $products->list(
            (int) $request->query('limit', 100),
            $request->boolean('active_only'),
        );

        return $this->successResponse(array_map(fn ($product): array => [
            'id' => $product->getId(),
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'category' => $product->getCategory(),
            'base_price_minor' => $product->getBasePriceMinor(),
            'base_cost_minor' => $product->getBaseCostMinor(),
            'currency' => $product->getCurrency(),
            'is_active' => $product->isActive(),
        ], $items));
    }

    public function store(
        StoreProductRequest $request,
        ProductCatalogService $service,
    ): JsonResponse {
        $product = $service->create($request->validated());

        return $this->successResponse([
            'id' => $product->getId(),
            'sku' => $product->getSku(),
        ], 'Producto creado.', 201);
    }
}
