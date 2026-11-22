<?php

namespace App\Modules\Billing\Controllers;

use App\Modules\Billing\Services\BillingAdminService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BillingAdminController
{
    use ApiResponse;

    public function overview(BillingAdminService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->overview());
    }

    public function plans(BillingAdminService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->plans());
    }

    public function subscriptions(Request $request, BillingAdminService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->subscriptions((int) $request->query('limit', 100)));
    }

    public function grant(Request $request, BillingAdminService $service): JsonResponse
    {
        $data = $request->validate([
            'partner_id' => ['nullable', 'string', 'max:64'],
            'user_id' => ['nullable', 'string', 'max:128'],
            'plan_id' => ['nullable', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:500'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        return $this->respond(fn (): array => $service->grant($data), 'Acceso manual otorgado.', 201);
    }

    public function revoke(string $id, BillingAdminService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->revoke($id), 'Acceso manual revocado.');
    }

    private function respond(callable $callback, string $message = 'Success', int $status = 200): JsonResponse
    {
        try {
            return $this->successResponse($callback(), $message, $status);
        } catch (\Throwable $exception) {
            $code = (int) $exception->getCode();
            $statusCode = in_array($code, [403, 404, 409, 422], true) ? $code : 500;
            if ($statusCode === 500) report($exception);
            return $this->errorResponse(
                $statusCode === 500 ? 'No fue posible completar la operación de facturación.' : $exception->getMessage(),
                'BILLING_ADMIN_FAILED',
                $statusCode,
            );
        }
    }
}
