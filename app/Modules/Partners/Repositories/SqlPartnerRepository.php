<?php

namespace App\Modules\Partners\Repositories;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Partners\Contracts\PartnerRepositoryInterface;
use App\Modules\Partners\Domain\Entities\Partner;
use App\Support\Database\GuardsTenant;
use App\Support\Database\SqlValue;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class SqlPartnerRepository implements PartnerRepositoryInterface
{
    use GuardsTenant;

    public function __construct(private readonly UserContextDTO $context) {}

    public function find(string $id): ?Partner
    {
        $this->guardTenant($id);
        $row = DB::table('partners')->where('id', $id)->first();
        return $row ? $this->map($row) : null;
    }

    public function save(Partner $partner): void
    {
        $this->guardTenant($partner->getId());
        DB::table('partners')->updateOrInsert(['id' => $partner->getId()], [
            'plan_id' => $partner->getPlanId(),
            'business_name' => $partner->getBusinessName(),
            'country' => $partner->getCountry(),
            'status' => $partner->getStatus(),
            'created_at' => SqlValue::timestamp($partner->getCreatedAt()),
            'updated_at' => now(),
            'deleted_at' => $partner->getDeletedAt() ? SqlValue::timestamp($partner->getDeletedAt()) : null,
        ]);
    }

    private function map(object $row): Partner
    {
        return new Partner(
            (string) $row->id,
            (string) $row->plan_id,
            (string) $row->business_name,
            (string) $row->country,
            (string) $row->status,
            SqlValue::date($row->created_at, new DateTimeImmutable()) ?? new DateTimeImmutable(),
            SqlValue::date($row->deleted_at),
        );
    }
}
