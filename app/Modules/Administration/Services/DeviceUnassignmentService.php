<?php

namespace App\Modules\Administration\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\IoT\Services\DeviceSecretCipher;
use App\Modules\Movements\Services\MovementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DeviceUnassignmentService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly DeviceSecretCipher $deviceSecrets,
        private readonly SecurityAuditService $audit,
        private readonly MovementService $movements,
    ) {}

    public function unassign(string $deviceId, string $reason): array
    {
        $this->requireSuperAdmin();
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Indica el motivo de la desvinculación.', 422);
        }

        return DB::transaction(function () use ($deviceId, $reason): array {
            $device = DB::table('devices')
                ->where('id', $deviceId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if ($device === null) {
                throw new \RuntimeException('Báscula no encontrada.', 404);
            }

            $previousPartnerId = trim((string) ($device->partner_id ?? ''));
            if ($previousPartnerId === '') {
                throw new \RuntimeException('La báscula ya está desvinculada y disponible para asignación.', 409);
            }

            $previousLocationId = $device->location_id !== null
                ? (string) $device->location_id
                : null;
            $previousStatus = (string) $device->status;
            $macAddress = (string) $device->mac_address;
            $measurementCount = (int) $device->total_measurements;

            // Desvincular las relaciones de eventos tradicionales sin borrar su historial.
            $legacyEventLinksReleased = 0;
            if (Schema::hasTable('campaign_devices')) {
                $legacyEventLinksReleased = DB::table('campaign_devices')
                    ->where('device_id', $deviceId)
                    ->where('status', 'ACTIVE')
                    // Mantener las asociaciones de eventos cerrados/cancelados para
                    // que sus vistas históricas sigan identificando la báscula usada.
                    ->whereExists(function ($query): void {
                        $query->selectRaw('1')
                            ->from('campaigns')
                            ->whereColumn('campaigns.id', 'campaign_devices.campaign_id')
                            ->whereIn('campaigns.status', ['DRAFT', 'SCHEDULED', 'ACTIVE'])
                            ->whereNull('campaigns.deleted_at');
                    })
                    ->update([
                        'status' => 'INACTIVE',
                        'unlinked_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            // Las básculas personales compartidas en eventos también se liberan.
            // Se conservan las filas y linked_device_id para que los eventos históricos
            // sigan pudiendo identificar qué equipo se utilizó y quién era su propietario.
            $personalDeviceIds = [];
            $personalDeviceLinksReleased = 0;
            $eventAvailabilityReleased = 0;

            if (Schema::hasTable('personal_devices')) {
                $personalDeviceIds = DB::table('personal_devices')
                    ->where('linked_device_id', $deviceId)
                    ->pluck('id')
                    ->map(static fn ($id): string => (string) $id)
                    ->all();

                if ($personalDeviceIds !== []) {
                    if (Schema::hasTable('campaign_device_availability')) {
                        $eventAvailabilityReleased = DB::table('campaign_device_availability')
                            ->whereIn('personal_device_id', $personalDeviceIds)
                            ->where('status', 'ACTIVE')
                            ->update([
                                'status' => 'RELEASED',
                                'released_at' => now(),
                                'updated_at' => now(),
                            ]);
                    }

                    $personalDeviceLinksReleased = DB::table('personal_devices')
                        ->whereIn('id', $personalDeviceIds)
                        ->where('status', '<>', 'REMOVED')
                        ->update([
                            'status' => 'REMOVED',
                            'updated_at' => now(),
                        ]);
                }
            }

            // Invalidar el secreto anterior: el ESP32 deja de autenticarse inmediatamente.
            $replacementSecret = bin2hex(random_bytes(32));
            DB::table('devices')->where('id', $deviceId)->update([
                'partner_id' => null,
                'location_id' => null,
                'status' => 'UNASSIGNED',
                'device_token' => $this->deviceSecrets->encrypt($replacementSecret),
                'suspended_at' => null,
                'suspended_by' => null,
                'suspension_reason' => null,
                'revoked_at' => null,
                'updated_at' => now(),
            ]);

            $metadata = [
                'mac_address' => $macAddress,
                'previous_partner_id' => $previousPartnerId,
                'previous_location_id' => $previousLocationId,
                'previous_status' => $previousStatus,
                'preserved_measurements' => $measurementCount,
                'legacy_event_links_released' => $legacyEventLinksReleased,
                'personal_device_links_released' => $personalDeviceLinksReleased,
                'event_availability_released' => $eventAvailabilityReleased,
                'new_status' => 'UNASSIGNED',
            ];

            $this->audit->record(
                'DEVICE',
                $deviceId,
                'UNASSIGN',
                $previousPartnerId,
                $reason,
                $metadata,
            );

            $movement = $this->movements->record(
                '804',
                $previousPartnerId,
                'DEVICE',
                $deviceId,
                $metadata,
                $reason,
            );

            return [
                'device_id' => $deviceId,
                'mac_address' => $macAddress,
                'status' => 'UNASSIGNED',
                'previous_partner_id' => $previousPartnerId,
                'previous_location_id' => $previousLocationId,
                'preserved_measurements' => $measurementCount,
                'released_event_links' => $legacyEventLinksReleased + $eventAvailabilityReleased,
                'movement' => $movement,
            ];
        }, 3);
    }

    private function requireSuperAdmin(): void
    {
        if (!$this->context->isSuperAdmin()) {
            throw new \RuntimeException('Solo SUPER_ADMIN puede desvincular básculas.', 403);
        }
    }
}
