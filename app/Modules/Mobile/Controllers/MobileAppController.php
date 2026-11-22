<?php

namespace App\Modules\Mobile\Controllers;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Billing\Services\BillingAccessService;
use App\Modules\Mobile\Services\MobileAppService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileAppController
{
    use ApiResponse;

    public function config(MobileAppService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->appConfig());
    }

    public function access(UserContextDTO $context, BillingAccessService $billing): JsonResponse
    {
        return $this->respond(fn (): array => $billing->access($context));
    }

    public function bootstrap(MobileAppService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->bootstrap());
    }

    public function dashboard(MobileAppService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->dashboard());
    }

    public function notifications(Request $request, MobileAppService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->notifications((int) $request->query('limit', 30)));
    }

    public function registerDevice(Request $request, MobileAppService $service): JsonResponse
    {
        $data = $request->validate([
            'installation_id' => ['required', 'string', 'max:180'],
            'platform' => ['required', 'in:ANDROID,IOS,WEB'],
            'app_version' => ['nullable', 'string', 'max:40'],
            'device_name' => ['nullable', 'string', 'max:180'],
            'device_model' => ['nullable', 'string', 'max:180'],
            'push_token' => ['nullable', 'string', 'max:4096'],
            'push_provider' => ['nullable', 'string', 'max:32'],
            'capabilities' => ['nullable', 'array', 'max:100'],
        ]);

        return $this->respond(fn (): array => $service->registerDevice($data), 'Dispositivo móvil registrado.');
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
                $statusCode === 500 ? 'No fue posible cargar la aplicación móvil.' : $exception->getMessage(),
                'MOBILE_APP_FAILED',
                $statusCode,
            );
        }
    }
}
