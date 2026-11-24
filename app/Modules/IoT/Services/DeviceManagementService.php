<?php

namespace App\Modules\IoT\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Partners\Services\PlanLimitService;
use App\Modules\Movements\Services\MovementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DeviceManagementService
{
    public function __construct(
        private readonly DeviceSecretCipher $cipher,
        private readonly UserContextDTO $context,
        private readonly PlanLimitService $limits,
        private readonly MovementService $movements,
    ) {}

    public function register(string $macAddress, ?string $locationId = null): array
    {
        $partnerId = $this->context->partnerId ?? throw new \RuntimeException('Contexto sin socio.', 403);
        $macAddress = strtoupper(trim($macAddress));
        if (!preg_match('/^(?:[0-9A-F]{2}:){5}[0-9A-F]{2}$/', $macAddress)) {
            throw new \InvalidArgumentException('MAC inválida.');
        }

        $this->limits->assertCanAddDevice($partnerId);

        if ($locationId === null || trim($locationId) === '') {
            $locationId = DB::table('locations')
                ->where('partner_id', $partnerId)
                ->where('status', 'ACTIVE')
                ->whereNull('deleted_at')
                ->orderBy('created_at')
                ->value('id');
            $locationId = $locationId !== null ? (string) $locationId : null;
        }

        if ($locationId === null) {
            throw new \RuntimeException('No existe una ubicación técnica activa para el socio.', 422);
        }

        if (!DB::table('locations')
            ->where('id', $locationId)
            ->where('partner_id', $partnerId)
            ->where('status', 'ACTIVE')
            ->whereNull('deleted_at')
            ->exists()) {
            throw new \RuntimeException('Ubicación inválida.', 422);
        }

        $deviceId = 'DEV_'.strtoupper((string) Str::ulid());
        $secret = bin2hex(random_bytes(32));

        $registration = DB::transaction(function () use (
            $deviceId,
            $macAddress,
            $partnerId,
            $locationId,
            $secret,
        ): array {
            // La MAC es única en devices. Si ya existe, sólo se puede recuperar
            // cuando SUPER_ADMIN la dejó expresamente en estado UNASSIGNED.
            $existing = DB::table('devices')
                ->where('mac_address', $macAddress)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->deleted_at !== null) {
                    throw new \RuntimeException('La MAC pertenece a un registro eliminado; requiere revisión de SUPER_ADMIN.', 409);
                }

                if (($existing->partner_id ?? null) !== null || (string) $existing->status !== 'UNASSIGNED') {
                    throw new \RuntimeException('La MAC ya está registrada y asignada a otro dispositivo o socio.', 409);
                }

                $reusedDeviceId = (string) $existing->id;
                $previousAssignment = DB::table('business_movements')
                    ->where('movement_code', '804')
                    ->where('target_type', 'DEVICE')
                    ->where('target_id', $reusedDeviceId)
                    ->orderByDesc('occurred_at')
                    ->first();

                DB::table('devices')->where('id', $reusedDeviceId)->update([
                    'partner_id' => $partnerId,
                    'location_id' => $locationId,
                    'device_token' => $this->cipher->encrypt($secret),
                    'status' => 'ACTIVE',
                    'linked_by_user_id' => $this->context->userId,
                    'suspended_at' => null,
                    'suspended_by' => null,
                    'suspension_reason' => null,
                    'revoked_at' => null,
                    'updated_at' => now(),
                ]);

                $movement = $this->movements->record(
                    '805',
                    $partnerId,
                    'DEVICE',
                    $reusedDeviceId,
                    [
                        'mac_address' => $macAddress,
                        'location_id' => $locationId,
                        'previous_partner_id' => $previousAssignment?->partner_id !== null
                            ? (string) $previousAssignment->partner_id
                            : null,
                        'reused_unassigned_device' => true,
                        'preserved_measurements' => (int) $existing->total_measurements,
                    ],
                );

                return [
                    'device_id' => $reusedDeviceId,
                    'movement' => $movement,
                    'reused_unassigned_device' => true,
                ];
            }

            DB::table('devices')->insert([
                'id' => $deviceId,
                'mac_address' => $macAddress,
                'partner_id' => $partnerId,
                'location_id' => $locationId,
                'device_token' => $this->cipher->encrypt($secret),
                'status' => 'ACTIVE',
                'total_measurements' => 0,
                'linked_by_user_id' => $this->context->userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $movement = $this->movements->record(
                '801',
                $partnerId,
                'DEVICE',
                $deviceId,
                ['mac_address' => $macAddress, 'location_id' => $locationId],
            );

            return [
                'device_id' => $deviceId,
                'movement' => $movement,
                'reused_unassigned_device' => false,
            ];
        }, 3);

        return [
            'device_id' => $registration['device_id'],
            'mac_address' => $macAddress,
            'device_secret' => $secret,
            'movement' => $registration['movement'],
            'reused_unassigned_device' => $registration['reused_unassigned_device'],
        ];
    }
}
