<?php

namespace App\Modules\Mobile\Controllers;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Campaigns\Services\CampaignService;
use App\Modules\CRM\Services\ContactManagementService;
use App\Modules\Mobile\Requests\MobileMeasurementRequest;
use App\Modules\Mobile\Services\MobileMeasurementService;
use App\Modules\Partners\Services\PartnerWorkModeService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileController
{
    use ApiResponse;

    public function context(
        Request $request,
        UserContextDTO $context,
        CampaignService $campaigns,
        PartnerWorkModeService $workMode,
    ): JsonResponse
    {
        $partnerId = $context->partnerId;
        if ($partnerId === null) {
            return $this->errorResponse('La aplicación móvil requiere un usuario asociado a un socio.', 'PARTNER_CONTEXT_REQUIRED', 403);
        }

        $partner = DB::table('partners')->where('id', $partnerId)->whereNull('deleted_at')->first();
        if ($partner === null) {
            return $this->errorResponse('Socio no encontrado.', 'PARTNER_NOT_FOUND', 404);
        }
        if ((string) $partner->status !== 'ACTIVE') {
            return $this->errorResponse('El socio está suspendido.', 'PARTNER_SUSPENDED', 403);
        }

        $user = DB::table('users')->where('id', $context->userId)->whereNull('deleted_at')->first();
        $devicesCollection = DB::table('devices')
            ->where('partner_id', $partnerId)
            ->whereNull('deleted_at')
            ->select('id', 'mac_address', 'status', 'last_measurement_at', 'total_measurements')
            ->orderBy('mac_address')
            ->get();

        $selector = strtoupper(trim((string) $request->query('device_id', '')));
        $selectedDevice = $selector === '' ? null : $devicesCollection->first(
            static fn (object $device): bool => strtoupper((string) $device->id) === $selector
                || strtoupper((string) $device->mac_address) === $selector,
        );

        $devices = $devicesCollection
            ->map(static function (object $device) use ($selectedDevice): array {
                $data = (array) $device;
                $data['selected'] = $selectedDevice !== null && (string) $selectedDevice->id === (string) $device->id;
                return $data;
            })
            ->all();

        $events = $campaigns->list(['from' => now()->subDays(30)->toAtomString()]);
        $campaignIds = array_values(array_filter(array_map(static fn (array $event): string => (string) ($event['id'] ?? ''), $events)));
        $deviceLinks = $campaignIds === []
            ? collect()
            : DB::table('campaign_devices')
                ->where('partner_id', $partnerId)
                ->whereIn('campaign_id', $campaignIds)
                ->where('status', 'ACTIVE')
                ->get(['campaign_id', 'device_id'])
                ->groupBy('campaign_id');

        $events = array_map(static function (array $event) use ($deviceLinks, $selectedDevice): array {
            $linkedIds = $deviceLinks->get((string) $event['id'], collect())
                ->pluck('device_id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();
            $event['device_ids'] = $linkedIds;
            $event['linked_to_selected_device'] = $selectedDevice !== null
                && in_array((string) $selectedDevice->id, $linkedIds, true);
            return $event;
        }, $events);

        $active = array_values(array_filter(
            $events,
            static fn (array $event): bool => ($event['status'] ?? null) === 'ACTIVE'
                && ($selectedDevice === null || (bool) ($event['linked_to_selected_device'] ?? false)),
        ));

        return $this->successResponse([
            'user' => [
                'id' => $context->userId,
                'name' => $user?->name,
                'email' => $user?->email,
                'role' => $context->role,
                'permissions' => $context->permissions,
            ],
            'partner' => [
                'id' => (string) $partner->id,
                'name' => (string) $partner->business_name,
                'status' => (string) $partner->status,
            ],
            'devices' => $devices,
            'selected_device' => $selectedDevice === null ? null : [
                'id' => (string) $selectedDevice->id,
                'mac_address' => (string) $selectedDevice->mac_address,
                'status' => (string) $selectedDevice->status,
            ],
            'events' => $events,
            'active_event' => $active[0] ?? null,
            'work_mode' => $workMode->describe(),
            'features' => [
                'mobile_kiosk_api' => true,
                'team_mode' => $workMode->isTeamMode($partnerId),
                'inventory_ledger_v2' => $this->featureEnabled($partnerId, 'inventory_ledger_v2'),
                'finance_reports_v2' => $this->featureEnabled($partnerId, 'finance_reports_v2'),
                'contact_measurement_dashboard_v1' => true,
            ],
        ]);
    }

    public function contactMeasurementDashboard(
        string $id,
        Request $request,
        ContactManagementService $contacts,
    ): JsonResponse {
        try {
            return $this->successResponse(
                $contacts->measurementDashboard($id, (int) $request->query('limit', 30)),
            );
        } catch (\Throwable $exception) {
            $code = (int) $exception->getCode();
            $status = in_array($code, [403, 404, 409, 422], true) ? $code : 500;
            if ($status === 500) report($exception);
            return $this->errorResponse(
                $status === 500 ? 'No fue posible consultar los resultados del pesaje.' : $exception->getMessage(),
                'MOBILE_MEASUREMENT_DASHBOARD_FAILED',
                $status,
            );
        }
    }

    public function measurement(MobileMeasurementRequest $request, MobileMeasurementService $service): JsonResponse
    {
        try {
            return $this->successResponse(
                $service->store($request->validated()),
                'Medición y prospecto sincronizados.',
                201,
            );
        } catch (\Throwable $exception) {
            $code = (int) $exception->getCode();
            $status = in_array($code, [403, 404, 409, 422, 429], true) ? $code : 500;
            if ($status === 500) {
                report($exception);
            }

            return $this->errorResponse(
                $status === 500 ? 'No fue posible sincronizar la medición.' : $exception->getMessage(),
                'MOBILE_MEASUREMENT_FAILED',
                $status,
            );
        }
    }

    private function featureEnabled(string $partnerId, string $feature): bool
    {
        return (bool) DB::table('feature_flags')
            ->where(function ($query) use ($partnerId): void {
                $query->where('partner_id', $partnerId)->orWhereNull('partner_id');
            })
            ->where('feature', $feature)
            ->orderByRaw('CASE WHEN partner_id IS NULL THEN 1 ELSE 0 END')
            ->value('enabled');
    }
}
