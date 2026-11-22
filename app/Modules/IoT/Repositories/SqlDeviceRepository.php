<?php

namespace App\Modules\IoT\Repositories;

use App\Modules\IoT\Contracts\DeviceRepositoryInterface;
use App\Modules\IoT\Domain\Entities\Device;
use App\Modules\IoT\Services\DeviceSecretCipher;
use App\Support\Database\SqlValue;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class SqlDeviceRepository implements DeviceRepositoryInterface
{
    public function __construct(private readonly DeviceSecretCipher $cipher) {}

    public function findByMac(string $macAddress): ?Device
    {
        $row = DB::table('devices')
            ->where('mac_address', strtoupper(trim($macAddress)))
            ->whereNull('deleted_at')
            ->first();
        return $row ? $this->map($row) : null;
    }

    public function find(string $id): ?Device
    {
        $row = DB::table('devices')->where('id', $id)->whereNull('deleted_at')->first();
        return $row ? $this->map($row) : null;
    }

    private function map(object $row): Device
    {
        return new Device(
            (string) $row->id,
            (string) $row->mac_address,
            (string) $row->partner_id,
            (string) $row->location_id,
            $this->cipher->decrypt((string) $row->device_token),
            (string) $row->status,
            (int) $row->total_measurements,
            SqlValue::date($row->created_at, new DateTimeImmutable()) ?? new DateTimeImmutable(),
            SqlValue::date($row->deleted_at),
        );
    }
}
