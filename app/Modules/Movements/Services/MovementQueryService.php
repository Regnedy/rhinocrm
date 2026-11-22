<?php

namespace App\Modules\Movements\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;

final class MovementQueryService
{
    public function __construct(private readonly UserContextDTO $context) {}

    /** @return array<int,object> */
    public function types(): array
    {
        return DB::table('movement_types')
            ->where('active', true)
            ->orderByRaw('CAST(code AS UNSIGNED)')
            ->get()
            ->all();
    }

    /** @return array{items:array<int,array<string,mixed>>,has_more:bool,next_cursor:?string} */
    public function list(array $filters): array
    {
        $limit = min(max((int) ($filters['limit'] ?? 100), 1), 250);
        $partnerId = $this->scopePartner($filters['partner_id'] ?? null);

        $query = DB::table('business_movements as m')
            ->leftJoin('movement_types as t', 't.code', '=', 'm.movement_code')
            ->leftJoin('partners as p', 'p.id', '=', 'm.partner_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.actor_user_id')
            ->select(
                'm.*',
                't.name as movement_name',
                'p.partner_number',
                'p.business_name as partner_name',
                'u.name as actor_name',
            );

        if ($partnerId !== null) {
            $query->where('m.partner_id', $partnerId);
        }
        if (!empty($filters['movement_code'])) {
            $query->where('m.movement_code', (string) $filters['movement_code']);
        }
        if (!empty($filters['module'])) {
            $query->where('m.module', strtoupper((string) $filters['module']));
        }
        if (!empty($filters['folio'])) {
            $query->where('m.folio', 'like', '%'.trim((string) $filters['folio']).'%');
        }
        if (!empty($filters['target_id'])) {
            $query->where('m.target_id', (string) $filters['target_id']);
        }
        if (!empty($filters['date_from'])) {
            $query->where('m.occurred_at', '>=', (string) $filters['date_from'].' 00:00:00');
        }
        if (!empty($filters['date_to'])) {
            $query->where('m.occurred_at', '<=', (string) $filters['date_to'].' 23:59:59');
        }
        if (!empty($filters['cursor'])) {
            $query->where('m.id', '<', (string) $filters['cursor']);
        }

        $rows = $query->orderByDesc('m.occurred_at')->orderByDesc('m.id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->map(fn (object $row): array => [
            'id' => (string) $row->id,
            'folio' => (string) $row->folio,
            'movement_code' => (string) $row->movement_code,
            'movement_name' => (string) ($row->movement_name ?? ''),
            'module' => (string) $row->module,
            'partner_id' => $row->partner_id !== null ? (string) $row->partner_id : null,
            'partner_number' => $row->partner_number !== null ? (string) $row->partner_number : null,
            'partner_name' => $row->partner_name !== null ? (string) $row->partner_name : null,
            'actor_user_id' => $row->actor_user_id !== null ? (string) $row->actor_user_id : null,
            'actor_name' => $row->actor_name !== null ? (string) $row->actor_name : null,
            'target_type' => (string) $row->target_type,
            'target_id' => $row->target_id !== null ? (string) $row->target_id : null,
            'reference_type' => $row->reference_type !== null ? (string) $row->reference_type : null,
            'reference_id' => $row->reference_id !== null ? (string) $row->reference_id : null,
            'status' => (string) $row->status,
            'reason' => $row->reason !== null ? (string) $row->reason : null,
            'ip_address' => $row->ip_address !== null ? (string) $row->ip_address : null,
            'access_channel' => $row->access_channel !== null ? (string) $row->access_channel : null,
            'metadata' => SqlValue::json($row->metadata),
            'occurred_at' => $row->occurred_at,
            'created_at' => $row->created_at,
        ])->values()->all();

        return [
            'items' => $items,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore && $items !== [] ? (string) end($items)['id'] : null,
        ];
    }

    private function scopePartner(mixed $requested): ?string
    {
        if ($this->context->isSuperAdmin()) {
            if ($requested === null || trim((string) $requested) === '') {
                return null;
            }
            $partnerId = trim((string) $requested);
            if (!DB::table('partners')->where('id', $partnerId)->whereNull('deleted_at')->exists()) {
                throw new \RuntimeException('Socio no encontrado.', 404);
            }
            return $partnerId;
        }

        $partnerId = $this->context->partnerId
            ?? throw new \RuntimeException('Contexto sin socio.', 403);

        if ($requested !== null && trim((string) $requested) !== '' && (string) $requested !== $partnerId) {
            throw new TenantViolationException($this->context->userId, (string) $requested);
        }

        return $partnerId;
    }
}
