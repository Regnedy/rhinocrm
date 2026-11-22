<?php

namespace App\Modules\Partners\Repositories;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Partners\Contracts\LocationRepositoryInterface;
use App\Modules\Partners\Domain\Entities\Location;
use App\Support\Database\GuardsTenant;
use App\Support\Database\SqlValue;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class SqlLocationRepository implements LocationRepositoryInterface
{
    use GuardsTenant;

    public function __construct(private readonly UserContextDTO $context) {}

    public function find(string $id): ?Location
    {
        $row = DB::table('locations')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }
        $this->guardTenant((string) $row->partner_id);
        return $this->map($row);
    }

    public function save(Location $location): void
    {
        $this->guardTenant($location->getPartnerId());
        DB::table('locations')->updateOrInsert(['id' => $location->getId()], [
            'partner_id' => $location->getPartnerId(),
            'name' => $location->getName(),
            'city' => $location->getCity(),
            'state' => $location->getState(),
            'status' => $location->getStatus(),
            'created_at' => SqlValue::timestamp($location->getCreatedAt()),
            'updated_at' => now(),
            'deleted_at' => $location->getDeletedAt() ? SqlValue::timestamp($location->getDeletedAt()) : null,
        ]);
    }

    private function map(object $row): Location
    {
        return new Location(
            (string) $row->id,
            (string) $row->partner_id,
            (string) $row->name,
            (string) ($row->city ?? ''),
            (string) ($row->state ?? ''),
            (string) $row->status,
            SqlValue::date($row->created_at, new DateTimeImmutable()) ?? new DateTimeImmutable(),
            SqlValue::date($row->deleted_at),
        );
    }
}
