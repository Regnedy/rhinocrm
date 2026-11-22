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
            $locationId = DB::table('locations')->where('partner_id', $partnerId)->where('status', 'ACTIVE')->whereNull('deleted_at')->orderBy('created_at')->value('id');
            $locationId = $locationId !== null ? (string) $locationId : null;
        }
        if ($locationId === null) {
            throw new \RuntimeException('No existe una ubicación técnica activa para el socio.', 422);
        }
        if (DB::table('devices')->where('mac_address', $macAddress)->whereNull('deleted_at')->exists()) {
            throw new \RuntimeException('La MAC ya está registrada.', 409);
        }
        if (!DB::table('locations')->where('id', $locationId)->where('partner_id', $partnerId)->where('status', 'ACTIVE')->whereNull('deleted_at')->exists()) {
            throw new \RuntimeException('Ubicación inválida.', 422);
        }

        $deviceId = 'DEV_'.strtoupper((string) Str::ulid());
        $secret = bin2hex(random_bytes(32));

        $movement = DB::transaction(function () use (
            $deviceId,
            $macAddress,
            $partnerId,
            $locationId,
            $secret,
        ): array {
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

            return $this->movements->record(
                '801',
                $partnerId,
                'DEVICE',
                $deviceId,
                ['mac_address' => $macAddress, 'location_id' => $locationId],
            );
        }, 3);

        return ['device_id' => $deviceId, 'mac_address' => $macAddress, 'device_secret' => $secret, 'movement' => $movement];
    }
}
