<?php

namespace App\Modules\IoT\Repositories;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\IoT\Contracts\MeasurementRepositoryInterface;
use App\Modules\IoT\Domain\Entities\Measurement;
use App\Modules\IoT\Domain\Exceptions\MeasurementContactConflictException;
use App\Support\Database\GuardsTenant;
use App\Support\Database\SqlValue;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class SqlMeasurementRepository implements MeasurementRepositoryInterface
{
    use GuardsTenant;

    public function __construct(private readonly UserContextDTO $context) {}

    public function exists(string $id): bool
    {
        return DB::table('measurements')->where('id', $id)->exists();
    }

    public function findById(string $id): ?Measurement
    {
        $row = DB::table('measurements')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }
        $this->guardTenant((string) $row->partner_id);
        return $this->map($row);
    }

    public function save(Measurement $measurement): void
    {
        $this->guardTenant($measurement->partnerId);
        $inserted = DB::table('measurements')->insertOrIgnore([
            'id' => $measurement->id,
            'device_id' => $measurement->deviceId,
            'partner_id' => $measurement->partnerId,
            'location_id' => $measurement->locationId,
            'contact_id' => $measurement->contactId,
            'biometrics_raw' => SqlValue::encode($measurement->rawBiometrics),
            'biometrics_calculated' => SqlValue::encode($measurement->calculatedBiometrics),
            'timestamp_local' => SqlValue::timestamp($measurement->timestampLocal),
            'received_at' => SqlValue::timestamp($measurement->receivedAt),
        ]);
        if ($inserted === 0) {
            throw new \RuntimeException('La medición ya existe.', 409);
        }
    }

    public function attachContactOnce(string $measurementId, string $contactId, string $partnerId): void
    {
        $this->guardTenant($partnerId);
        DB::transaction(function () use ($measurementId, $contactId, $partnerId): void {
            $row = DB::table('measurements')->where('id', $measurementId)->lockForUpdate()->first();
            if ($row === null) {
                throw new \RuntimeException("Medición {$measurementId} no encontrada.", 404);
            }
            $this->guardTenant((string) $row->partner_id);
            if ((string) $row->partner_id !== $partnerId) {
                throw new \RuntimeException('La medición no pertenece al socio.', 403);
            }
            if ($row->contact_id === $contactId) {
                return;
            }
            if ($row->contact_id !== null) {
                throw new MeasurementContactConflictException($measurementId);
            }
            DB::table('measurements')->where('id', $measurementId)->update(['contact_id' => $contactId]);
        }, 3);
    }

    private function map(object $row): Measurement
    {
        return new Measurement(
            (string) $row->id,
            (string) $row->device_id,
            (string) $row->partner_id,
            (string) $row->location_id,
            $row->contact_id !== null ? (string) $row->contact_id : null,
            SqlValue::json($row->biometrics_raw),
            SqlValue::json($row->biometrics_calculated),
            SqlValue::date($row->timestamp_local, new DateTimeImmutable()) ?? new DateTimeImmutable(),
            SqlValue::date($row->received_at, new DateTimeImmutable()) ?? new DateTimeImmutable(),
        );
    }
}
