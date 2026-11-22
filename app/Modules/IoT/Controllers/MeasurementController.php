<?php

namespace App\Modules\IoT\Controllers;

use App\Modules\IoT\Domain\Exceptions\DuplicateMeasurementException;
use App\Modules\IoT\Requests\StoreMeasurementRequest;
use App\Modules\IoT\Services\MeasurementIngestionService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

final class MeasurementController
{
    use ApiResponse;

    public function store(
        StoreMeasurementRequest $request,
        MeasurementIngestionService $service,
    ): JsonResponse {
        try {
            $measurement = $service->ingest($request->validated());

            return $this->successResponse([
                'measurement_id' => $measurement->id,
            ], 'Medición registrada exitosamente.', 201);
        } catch (DuplicateMeasurementException $exception) {
            return $this->errorResponse($exception->getMessage(), 'DUPLICATE_MEASUREMENT', 409);
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse($exception->getMessage(), 'PAYLOAD_DEVICE_MISMATCH', 422);
        } catch (\RuntimeException $exception) {
            $status = in_array((int) $exception->getCode(), [409, 429], true)
                ? (int) $exception->getCode()
                : 500;

            if ($status >= 500) {
                report($exception);
            }

            return $this->errorResponse(
                $status >= 500 ? 'No fue posible registrar la medición.' : $exception->getMessage(),
                $status === 429 ? 'PLAN_MEASUREMENT_LIMIT_REACHED' : 'INGESTION_ERROR',
                $status,
            );
        } catch (\Throwable $exception) {
            report($exception);

            return $this->errorResponse('No fue posible registrar la medición.', 'INGESTION_ERROR', 500);
        }
    }
}
