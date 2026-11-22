<?php

namespace App\Modules\Auth\Repositories;

use App\Modules\Auth\Contracts\UserRepositoryInterface;
use App\Modules\Auth\Domain\Entities\User;
use App\Modules\Auth\DTOs\UserContextDTO;
use App\Support\Database\GuardsTenant;
use App\Support\Database\SqlValue;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class SqlUserRepository implements UserRepositoryInterface
{
    use GuardsTenant;

    public function __construct(private readonly UserContextDTO $context) {}

    public function find(string $id): ?User
    {
        $row = DB::table('users')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }
        $this->guardTenant($row->partner_id !== null ? (string) $row->partner_id : null);

        return $this->map($row);
    }

    public function findByEmail(string $email): ?User
    {
        $row = DB::table('users')->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->first();
        if ($row === null) {
            return null;
        }
        $this->guardTenant($row->partner_id !== null ? (string) $row->partner_id : null);

        return $this->map($row);
    }

    public function save(User $user): void
    {
        $this->guardTenant($user->getPartnerId());
        $now = now();
        DB::table('users')->updateOrInsert(
            ['id' => $user->getId()],
            [
                'partner_id' => $user->getPartnerId(),
                'location_id' => $user->getLocationId(),
                'role' => $user->getRole(),
                'name' => $user->getName(),
                'email' => mb_strtolower($user->getEmail()),
                'status' => $user->getStatus(),
                'created_at' => SqlValue::timestamp($user->getCreatedAt()),
                'updated_at' => $now,
                'deleted_at' => $user->getDeletedAt() ? SqlValue::timestamp($user->getDeletedAt()) : null,
            ],
        );
    }

    public function findActiveAdvisors(string $partnerId, string $locationId): array
    {
        $this->guardTenant($partnerId);
        return DB::table('users')
            ->where('partner_id', $partnerId)
            ->where('location_id', $locationId)
            ->whereIn('role', ['ADVISOR', 'TEAM_LEADER'])
            ->where('status', 'ACTIVE')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): User => $this->map($row))
            ->all();
    }

    public function listForPartner(string $partnerId, int $limit = 200): array
    {
        $this->guardTenant($partnerId);
        return DB::table('users')
            ->where('partner_id', $partnerId)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->limit(min(max($limit, 1), 500))
            ->get()
            ->map(fn (object $row): User => $this->map($row))
            ->all();
    }

    private function map(object $row): User
    {
        return new User(
            id: (string) $row->id,
            partnerId: $row->partner_id !== null ? (string) $row->partner_id : null,
            role: (string) $row->role,
            name: (string) $row->name,
            email: (string) $row->email,
            status: (string) $row->status,
            locationId: $row->location_id !== null ? (string) $row->location_id : null,
            createdAt: SqlValue::date($row->created_at, new DateTimeImmutable()) ?? new DateTimeImmutable(),
            deletedAt: SqlValue::date($row->deleted_at),
        );
    }
}
