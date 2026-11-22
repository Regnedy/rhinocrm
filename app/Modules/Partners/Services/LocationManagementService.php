<?php

namespace App\Modules\Partners\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LocationManagementService
{
    public function __construct(private readonly UserContextDTO $context) {}

    public function list(?string $requestedPartnerId = null): array
    {
        $partnerId = $this->resolvePartnerId($requestedPartnerId);

        return DB::table('locations')
            ->where('partner_id', $partnerId)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'partner_id', 'name', 'city', 'state', 'status', 'created_at', 'updated_at'])
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    public function create(array $data, ?string $requestedPartnerId = null): array
    {
        $partnerId = $this->resolvePartnerId($requestedPartnerId);
        if (!DB::table('partners')->where('id', $partnerId)->where('status', 'ACTIVE')->whereNull('deleted_at')->exists()) {
            throw new \RuntimeException('Socio no encontrado o suspendido.', 422);
        }

        $duplicate = DB::table('locations')
            ->where('partner_id', $partnerId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $data['name']))])
            ->whereNull('deleted_at')
            ->exists();
        if ($duplicate) {
            throw new \RuntimeException('Ya existe una sucursal con ese nombre.', 409);
        }

        $id = 'LOC_'.strtoupper((string) Str::ulid());
        DB::table('locations')->insert([
            'id' => $id,
            'partner_id' => $partnerId,
            'name' => trim((string) $data['name']),
            'city' => isset($data['city']) ? trim((string) $data['city']) : null,
            'state' => isset($data['state']) ? trim((string) $data['state']) : null,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $id,
            'partner_id' => $partnerId,
            'name' => trim((string) $data['name']),
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'status' => 'ACTIVE',
        ];
    }

    private function resolvePartnerId(?string $requestedPartnerId): string
    {
        if ($this->context->isSuperAdmin()) {
            if ($requestedPartnerId === null || trim($requestedPartnerId) === '') {
                throw new \RuntimeException('SUPER_ADMIN debe indicar partner_id.', 422);
            }
            return trim($requestedPartnerId);
        }

        return $this->context->partnerId ?? throw new \RuntimeException('Contexto sin socio.', 403);
    }
}
