<?php

namespace App\Modules\Sales\Controllers;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Sales\Requests\CreateSaleRequest;
use App\Modules\Sales\Services\SaleProcessingService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SaleController
{
    use ApiResponse;

    public function index(Request $request, UserContextDTO $context): JsonResponse
    {
        $partnerId = $context->partnerId ?? throw new \RuntimeException('Contexto sin socio.', 403);
        $query = DB::table('sales as s')->join('contacts as c', 'c.id', '=', 's.contact_id')
            ->leftJoin('users as u', 'u.id', '=', 's.advisor_id')->where('s.partner_id', $partnerId)
            ->select('s.*', 'c.name as contact_name', 'u.name as advisor_name');
        if ($context->role === 'ADVISOR') $query->where('s.advisor_id', $context->userId);
        if ($request->filled('status')) $query->where('s.status', strtoupper((string)$request->query('status')));
        if ($request->filled('team_id')) $query->where('s.team_id', $request->query('team_id'));
        return $this->successResponse($query->orderByDesc('s.closed_at')->limit(min(max((int)$request->query('limit',100),1),500))->get()->all());
    }

    public function show(string $id, UserContextDTO $context): JsonResponse
    {
        $partnerId = $context->partnerId ?? throw new \RuntimeException('Contexto sin socio.', 403);
        $sale = DB::table('sales')->where('id', $id)->where('partner_id', $partnerId)->first();
        if ($sale === null || ($context->role === 'ADVISOR' && (string)$sale->advisor_id !== $context->userId)) {
            return $this->errorResponse('Venta no encontrada.', 'SALE_NOT_FOUND', 404);
        }
        $data = (array)$sale;
        $data['items'] = DB::table('sale_items')->where('sale_id', $id)->get()->all();
        return $this->successResponse($data);
    }

    public function store(
        CreateSaleRequest $request,
        SaleProcessingService $service,
        UserContextDTO $context,
    ): JsonResponse {
        $idempotencyKey = (string) $request->header('X-Idempotency-Key');
        if ($idempotencyKey === '') {
            return $this->errorResponse(
                'X-Idempotency-Key es obligatorio.',
                'IDEMPOTENCY_KEY_REQUIRED',
                422,
            );
        }

        try {
            $data = $request->validated();
            $sale = $service->processSale(
                $data['contact_id'],
                $context->locationId ?? $data['location_id'],
                $data['payment_method'],
                $data['cart'],
                $idempotencyKey,
            );

            return $this->successResponse([
                'sale_id' => $sale->getId(),
                'revenue' => $sale->getTotalRevenue(),
                'profit' => $sale->getTotalProfit(),
                'currency' => $sale->getCurrencySnapshot(),
                'type' => $sale->getSaleType(),
            ], 'Venta registrada.', 201);
        } catch (\Throwable $exception) {
            $status = in_array((int) $exception->getCode(), [403, 404, 409], true)
                ? (int) $exception->getCode()
                : 422;

            return $this->errorResponse($exception->getMessage(), 'SALE_PROCESSING_FAILED', $status);
        }
    }

    public function cancel(
        string $id,
        Request $request,
        SaleProcessingService $service,
    ): JsonResponse {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        try {
            $service->cancelSale($id, $data['reason']);

            return $this->successResponse([], 'Venta reembolsada.');
        } catch (\Throwable $exception) {
            $status = in_array((int) $exception->getCode(), [403, 404, 409], true)
                ? (int) $exception->getCode()
                : 422;

            return $this->errorResponse($exception->getMessage(), 'REFUND_FAILED', $status);
        }
    }
}
