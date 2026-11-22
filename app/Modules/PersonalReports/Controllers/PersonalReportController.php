<?php

namespace App\Modules\PersonalReports\Controllers;

use App\Modules\PersonalReports\Services\EventReportService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

final class PersonalReportController
{
    use ApiResponse;

    public function summary(string $campaignId, EventReportService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->summary($campaignId));
    }

    public function dispatch(string $campaignId, EventReportService $service): JsonResponse
    {
        return $this->respond(
            fn (): array => $service->dispatch($campaignId),
            'Envío de informes programado.',
            202,
        );
    }

    private function respond(callable $callback, string $message = 'Success', int $status = 200): JsonResponse
    {
        try {
            return $this->successResponse($callback(), $message, $status);
        } catch (\Throwable $exception) {
            $code = (int) $exception->getCode();
            $statusCode = in_array($code, [403, 404, 409, 422], true) ? $code : 500;
            if ($statusCode === 500) {
                report($exception);
            }
            return $this->errorResponse(
                $statusCode === 500 ? 'No fue posible preparar los informes del evento.' : $exception->getMessage(),
                'PERSONAL_REPORT_OPERATION_FAILED',
                $statusCode,
            );
        }
    }
}
