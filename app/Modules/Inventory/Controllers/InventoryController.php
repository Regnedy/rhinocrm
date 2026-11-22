<?php

namespace App\Modules\Inventory\Controllers;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Inventory\Requests\AdjustInventoryRequest;
use App\Modules\Inventory\Services\InventoryAdjustmentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class InventoryController
{
    use ApiResponse;

    public function index(Request $request, UserContextDTO $context): JsonResponse
    {
        $partnerId = $context->partnerId ?? throw new \RuntimeException('Contexto sin socio.', 403);
        $query = DB::table('inventory_balances as b')->join('products as p', 'p.id', '=', 'b.product_id')
            ->join('locations as l', 'l.id', '=', 'b.location_id')->where('b.partner_id', $partnerId)
            ->select('b.product_id','p.sku','p.name','b.location_id','l.name as location_name','b.quantity','b.updated_at');
        if ($request->filled('location_id')) $query->where('b.location_id', $request->query('location_id'));
        if ($request->filled('product_id')) $query->where('b.product_id', $request->query('product_id'));
        return $this->successResponse($query->orderBy('p.name')->limit(500)->get()->all());
    }

    public function movements(Request $request, UserContextDTO $context): JsonResponse
    {
        $partnerId = $context->partnerId ?? throw new \RuntimeException('Contexto sin socio.', 403);
        $query = DB::table('inventory_transactions as t')->leftJoin('products as p', 'p.id', '=', 't.product_id')
            ->where('t.partner_id', $partnerId)->select('t.*','p.name as product_name');
        if ($request->filled('location_id')) $query->where('t.location_id', $request->query('location_id'));
        if ($request->filled('product_id')) $query->where('t.product_id', $request->query('product_id'));
        return $this->successResponse($query->orderByDesc('t.created_at')->limit(min(max((int)$request->query('limit',100),1),500))->get()->all());
    }

    public function adjust(AdjustInventoryRequest $request, InventoryAdjustmentService $service): JsonResponse
    {
        $idempotencyKey = (string) $request->header('X-Idempotency-Key');
        if ($idempotencyKey === '') return $this->errorResponse('X-Idempotency-Key es obligatorio.','IDEMPOTENCY_KEY_REQUIRED',422);
        try {
            return $this->successResponse($service->adjust($request->validated(), $idempotencyKey), 'Inventario ajustado.');
        } catch (\Throwable $exception) {
            $status = in_array((int) $exception->getCode(), [403,404,409,422], true) ? (int)$exception->getCode() : 422;
            return $this->errorResponse($exception->getMessage(),'INVENTORY_ADJUSTMENT_FAILED',$status);
        }
    }
}
