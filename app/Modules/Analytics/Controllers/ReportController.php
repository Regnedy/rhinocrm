<?php

namespace App\Modules\Analytics\Controllers;

use App\Modules\Analytics\Requests\CreateReportRequest;
use App\Modules\Analytics\Services\ReportExportService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportController
{
    use ApiResponse;

    public function store(
        CreateReportRequest $request,
        ReportExportService $service,
    ): JsonResponse {
        $data = $request->validated();
        $reportId = $service->request($data['collection'], $data['columns']);

        return $this->successResponse(['report_id' => $reportId], 'Reporte encolado.', 202);
    }

    public function show(string $id, ReportExportService $service): JsonResponse
    {
        try {
            return $this->successResponse($service->status($id));
        } catch (\Throwable $exception) {
            $status = in_array((int) $exception->getCode(), [403, 404], true)
                ? (int) $exception->getCode()
                : 422;

            return $this->errorResponse($exception->getMessage(), 'REPORT_STATUS_FAILED', $status);
        }
    }
    public function download(string $id, ReportExportService $service): StreamedResponse
    {
        return $service->download($id);
    }

}
