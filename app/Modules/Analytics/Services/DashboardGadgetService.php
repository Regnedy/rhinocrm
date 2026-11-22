<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Support\Database\SqlValue;
use App\Support\Database\TeamAccessScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class DashboardGadgetService
{
    public function __construct(private readonly UserContextDTO $context) {}

    /** @return array<string,mixed> */
    public function workspace(string $period): array
    {
        $available = $this->availableGadgets();
        $layout = $this->layout($available);
        $catalogByCode = [];
        foreach ($available as $gadget) {
            $catalogByCode[$gadget['code']] = $gadget;
        }

        $items = [];
        foreach ($layout as $entry) {
            if (!(bool) ($entry['enabled'] ?? true)) {
                continue;
            }
            $code = (string) ($entry['code'] ?? '');
            if (!isset($catalogByCode[$code])) {
                continue;
            }
            $catalog = $catalogByCode[$code];
            $items[] = [
                'code' => $code,
                'name' => $catalog['name'],
                'description' => $catalog['description'],
                'module' => $catalog['module'],
                'width' => (int) ($entry['width'] ?? $catalog['default_width']),
                'data' => $this->gadgetData($code, $period),
            ];
        }

        return [
            'period' => $period,
            'available_gadgets' => $available,
            'layout' => $layout,
            'items' => $items,
        ];
    }

    /** @param array<int,array<string,mixed>> $requested @return array<int,array<string,mixed>> */
    public function saveLayout(array $requested): array
    {
        $available = $this->availableGadgets();
        $byCode = [];
        foreach ($available as $gadget) {
            $byCode[$gadget['code']] = $gadget;
        }

        $seen = [];
        $layout = [];
        foreach (array_slice($requested, 0, 40) as $position => $entry) {
            $code = strtoupper(trim((string) ($entry['code'] ?? '')));
            if ($code === '' || isset($seen[$code]) || !isset($byCode[$code])) {
                continue;
            }
            $seen[$code] = true;
            $allowedWidths = array_map('intval', $byCode[$code]['allowed_widths']);
            $width = (int) ($entry['width'] ?? $byCode[$code]['default_width']);
            if (!in_array($width, $allowedWidths, true)) {
                $width = (int) $byCode[$code]['default_width'];
            }
            $layout[] = [
                'code' => $code,
                'enabled' => (bool) ($entry['enabled'] ?? true),
                'width' => $width,
                'position' => $position + 1,
            ];
        }

        foreach ($available as $gadget) {
            if (isset($seen[$gadget['code']])) {
                continue;
            }
            $layout[] = [
                'code' => $gadget['code'],
                'enabled' => false,
                'width' => (int) $gadget['default_width'],
                'position' => count($layout) + 1,
            ];
        }

        DB::table('dashboard_layouts')->updateOrInsert(
            ['user_id' => $this->context->userId],
            [
                'id' => $this->layoutId(),
                'partner_id' => $this->context->partnerId,
                'layout' => SqlValue::encode($layout),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return $layout;
    }

    /** @return array<int,array<string,mixed>> */
    public function availableGadgets(): array
    {
        if (!Schema::hasTable('dashboard_gadgets')) {
            return [];
        }

        return DB::table('dashboard_gadgets')
            ->where('status', 'ACTIVE')
            ->orderBy('sort_order')
            ->get()
            ->filter(function (object $row): bool {
                $permission = trim((string) ($row->required_permission ?? ''));
                if ($permission !== '' && !$this->context->hasPermission($permission)) {
                    return false;
                }
                $roles = SqlValue::json($row->allowed_roles ?? null);
                return $roles === [] || in_array($this->context->role, array_map('strval', $roles), true);
            })
            ->map(fn (object $row): array => [
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'description' => (string) ($row->description ?? ''),
                'module' => (string) $row->module,
                'default_width' => (int) $row->default_width,
                'allowed_widths' => array_values(array_map('intval', SqlValue::json($row->allowed_widths ?? null))),
                'default_enabled' => (bool) $row->default_enabled,
                'sort_order' => (int) $row->sort_order,
            ])
            ->values()
            ->all();
    }

    /** @param array<int,array<string,mixed>> $available @return array<int,array<string,mixed>> */
    private function layout(array $available): array
    {
        $stored = Schema::hasTable('dashboard_layouts')
            ? DB::table('dashboard_layouts')->where('user_id', $this->context->userId)->first()
            : null;
        $layout = $stored !== null ? SqlValue::json($stored->layout ?? null) : [];
        if ($layout !== []) {
            usort($layout, static fn (array $a, array $b): int => ((int) ($a['position'] ?? 0)) <=> ((int) ($b['position'] ?? 0)));
            return $layout;
        }

        return array_map(
            static fn (array $gadget, int $index): array => [
                'code' => $gadget['code'],
                'enabled' => (bool) $gadget['default_enabled'],
                'width' => (int) $gadget['default_width'],
                'position' => $index + 1,
            ],
            $available,
            array_keys($available),
        );
    }

    /** @return array<string,mixed> */
    private function gadgetData(string $code, string $period): array
    {
        return match ($code) {
            'PROSPECTS_NEW' => $this->prospects(),
            'TASKS_PENDING' => $this->tasks(),
            'NUTRITION_PLANS' => $this->nutrition(),
            'MEASUREMENTS_RECENT' => $this->measurements(),
            'EVENTS_UPCOMING' => $this->events(),
            'ORGANIZATION_SUMMARY' => $this->organization(),
            'DEVICES_ACTIVE' => $this->devices(),
            'MOVEMENTS_RECENT' => $this->movements(),
            'SALES_MONTH' => $this->sales($period),
            default => ['type' => 'empty', 'message' => 'Gadget sin proveedor de datos.'],
        };
    }

    /** @return array<string,mixed> */
    private function prospects(): array
    {
        if (!Schema::hasTable('contacts')) {
            return ['type' => 'metric_group', 'metrics' => []];
        }

        $query = $this->contactQuery();
        $active = (clone $query)->where('status', 'ACTIVE')->count();
        $new = (clone $query)->where('status', 'ACTIVE')->where('sales_stage', 'NEW')->count();
        $withoutFollowUp = (clone $query)
            ->where('status', 'ACTIVE')
            ->whereNotExists(function (Builder $task): void {
                $task->selectRaw('1')
                    ->from('tasks')
                    ->whereColumn('tasks.contact_id', 'contacts.id')
                    ->where('tasks.status', 'PENDING');
            })
            ->count();

        return [
            'type' => 'metric_group',
            'metrics' => [
                ['label' => 'Activos', 'value' => $active],
                ['label' => 'Nuevos', 'value' => $new],
                ['label' => 'Sin seguimiento', 'value' => $withoutFollowUp],
            ],
            'link' => '#prospects',
        ];
    }

    /** @return array<string,mixed> */
    private function tasks(): array
    {
        if (!Schema::hasTable('tasks')) {
            return ['type' => 'metric_group', 'metrics' => []];
        }

        $query = DB::table('tasks')->where('status', 'PENDING');
        $this->scopePartner($query);
        if ($this->context->role === 'ADVISOR') {
            $query->where('assigned_user_id', $this->context->userId);
        } elseif ($this->context->role === 'TEAM_LEADER') {
            (new TeamAccessScope($this->context))->applyToTasks($query);
        }

        $pending = (clone $query)->count();
        $overdue = (clone $query)->where('due_at', '<', now())->count();
        $today = (clone $query)->whereDate('due_at', now()->toDateString())->count();

        return [
            'type' => 'metric_group',
            'metrics' => [
                ['label' => 'Pendientes', 'value' => $pending],
                ['label' => 'Hoy', 'value' => $today],
                ['label' => 'Vencidas', 'value' => $overdue],
            ],
            'link' => '#agenda',
        ];
    }

    /** @return array<string,mixed> */
    private function nutrition(): array
    {
        if (!Schema::hasTable('nutrition_plan_versions')) {
            return ['type' => 'metric_group', 'metrics' => []];
        }

        $query = DB::table('nutrition_plan_versions as plan')->where('plan.is_current', true);
        $this->scopePartner($query, 'plan.partner_id');
        $this->applyVisibleContact($query, 'plan.contact_id');

        return [
            'type' => 'metric_group',
            'metrics' => [
                ['label' => 'Vigentes', 'value' => (clone $query)->count()],
                ['label' => 'Sin enviar', 'value' => (clone $query)->where('plan.delivery_status', 'NOT_SENT')->count()],
                ['label' => 'Revisión', 'value' => (clone $query)->where('plan.requires_professional_review', true)->count()],
            ],
            'link' => '#nutrition',
        ];
    }

    /** @return array<string,mixed> */
    private function measurements(): array
    {
        if (!Schema::hasTable('measurements')) {
            return ['type' => 'metric_group', 'metrics' => []];
        }

        $query = DB::table('measurements');
        $this->scopePartner($query);
        if (in_array($this->context->role, ['ADVISOR', 'TEAM_LEADER'], true)) {
            $query->whereExists(function (Builder $contact): void {
                $contact->selectRaw('1')
                    ->from('contacts')
                    ->whereColumn('contacts.id', 'measurements.contact_id');
                $this->scopeContactBuilder($contact);
            });
        }

        return [
            'type' => 'metric_group',
            'metrics' => [
                ['label' => 'Hoy', 'value' => (clone $query)->whereDate('timestamp_local', now()->toDateString())->count()],
                ['label' => '7 días', 'value' => (clone $query)->where('timestamp_local', '>=', now()->subDays(7))->count()],
                ['label' => '30 días', 'value' => (clone $query)->where('timestamp_local', '>=', now()->subDays(30))->count()],
            ],
            'link' => '#prospects',
        ];
    }

    /** @return array<string,mixed> */
    private function events(): array
    {
        if (!Schema::hasTable('campaigns')) {
            return ['type' => 'list', 'items' => []];
        }

        $query = DB::table('campaigns')
            ->whereNull('deleted_at')
            ->whereIn('status', ['SCHEDULED', 'READY', 'ACTIVE'])
            ->where('starts_at', '>=', now()->subDay())
            ->orderBy('starts_at')
            ->limit(5);
        $this->scopePartner($query);

        if ($this->context->role === 'ADVISOR') {
            $query->where(function (Builder $scope): void {
                $scope->where('responsible_user_id', $this->context->userId)
                    ->orWhereExists(function (Builder $member): void {
                        $member->selectRaw('1')
                            ->from('campaign_members')
                            ->whereColumn('campaign_members.campaign_id', 'campaigns.id')
                            ->where('campaign_members.user_id', $this->context->userId)
                            ->where('campaign_members.status', 'ACTIVE');
                    });
            });
        }

        $items = $query->get()->map(fn (object $row): array => [
            'title' => (string) $row->name,
            'meta' => (string) $row->starts_at,
            'status' => (string) $row->status,
            'href' => '#events/'.rawurlencode((string) $row->id),
        ])->all();

        return ['type' => 'list', 'items' => $items, 'link' => '#events'];
    }

    /** @return array<string,mixed> */
    private function organization(): array
    {
        if ($this->context->partnerId === null || !Schema::hasTable('partners')) {
            $totals = Schema::hasTable('partners')
                ? [
                    ['label' => 'Socios', 'value' => DB::table('partners')->whereNull('deleted_at')->count()],
                    ['label' => 'Activos', 'value' => DB::table('partners')->whereNull('deleted_at')->where('status', 'ACTIVE')->count()],
                    ['label' => 'Suspendidos', 'value' => DB::table('partners')->whereNull('deleted_at')->where('status', 'SUSPENDED')->count()],
                ]
                : [];
            return ['type' => 'metric_group', 'metrics' => $totals, 'link' => '#partners'];
        }

        $partner = DB::table('partners as p')
            ->leftJoin('partner_ranks as r', 'r.id', '=', 'p.current_rank_id')
            ->leftJoin('partners as sponsor', 'sponsor.id', '=', 'p.sponsor_partner_id')
            ->where('p.id', $this->context->partnerId)
            ->select('p.business_name', 'p.partner_number', 'r.name as rank_name', 'sponsor.business_name as sponsor_name')
            ->first();

        if ($partner === null) {
            return ['type' => 'summary', 'rows' => [], 'link' => '#organization'];
        }

        return [
            'type' => 'summary',
            'rows' => [
                ['label' => 'Socio ID', 'value' => (string) ($partner->partner_number ?? '—')],
                ['label' => 'Rango', 'value' => (string) ($partner->rank_name ?? 'Sin rango')],
                ['label' => 'Upline directo', 'value' => (string) ($partner->sponsor_name ?? 'Sin asignar')],
            ],
            'link' => '#organization',
        ];
    }

    /** @return array<string,mixed> */
    private function devices(): array
    {
        $partnerId = $this->context->partnerId;
        $sessions = Schema::hasTable('api_sessions')
            ? DB::table('api_sessions')
                ->when($partnerId !== null, fn (Builder $query) => $query->where('partner_id', $partnerId))
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->count()
            : 0;
        $devices = Schema::hasTable('devices')
            ? DB::table('devices')
                ->when($partnerId !== null, fn (Builder $query) => $query->where('partner_id', $partnerId))
                ->whereNull('deleted_at')
                ->where('status', 'ACTIVE')
                ->count()
            : 0;

        return [
            'type' => 'metric_group',
            'metrics' => [
                ['label' => 'Sesiones', 'value' => $sessions],
                ['label' => 'Básculas', 'value' => $devices],
            ],
            'link' => '#devices',
        ];
    }

    /** @return array<string,mixed> */
    private function movements(): array
    {
        if (!Schema::hasTable('business_movements')) {
            return ['type' => 'list', 'items' => []];
        }

        $query = DB::table('business_movements as movement')
            ->leftJoin('movement_types as type', 'type.code', '=', 'movement.movement_code')
            ->orderByDesc('movement.occurred_at')
            ->limit(6);
        $this->scopePartner($query, 'movement.partner_id');

        $items = $query->get([
            'movement.folio',
            'movement.movement_code',
            'movement.occurred_at',
            'type.name',
        ])->map(fn (object $row): array => [
            'title' => (string) $row->folio.' · '.(string) ($row->name ?? $row->movement_code),
            'meta' => (string) $row->occurred_at,
            'status' => (string) $row->movement_code,
            'href' => '#movements',
        ])->all();

        return ['type' => 'list', 'items' => $items, 'link' => '#movements'];
    }

    /** @return array<string,mixed> */
    private function sales(string $period): array
    {
        if (!Schema::hasTable('sales')) {
            return ['type' => 'metric_group', 'metrics' => []];
        }

        $start = $period.'-01 00:00:00';
        $end = date('Y-m-d H:i:s', strtotime($start.' +1 month'));
        $query = DB::table('sales')->where('closed_at', '>=', $start)->where('closed_at', '<', $end);
        $this->scopePartner($query);
        if ($this->context->role === 'ADVISOR') {
            $query->where('advisor_id', $this->context->userId);
        } elseif ($this->context->role === 'TEAM_LEADER') {
            $scope = new TeamAccessScope($this->context);
            $query->where(function (Builder $visibility) use ($scope): void {
                $teamIds = $scope->teamIds();
                $userIds = $scope->memberUserIds();
                if ($teamIds !== []) $visibility->whereIn('team_id', $teamIds);
                if ($userIds !== []) {
                    $method = $teamIds === [] ? 'whereIn' : 'orWhereIn';
                    $visibility->{$method}('advisor_id', $userIds);
                }
            });
        }

        return [
            'type' => 'metric_group',
            'metrics' => [
                ['label' => 'Ventas', 'value' => (clone $query)->where('status', 'COMPLETED')->count()],
                ['label' => 'Ingresos', 'value' => (int) ((clone $query)->where('status', 'COMPLETED')->sum('total_revenue_minor') ?? 0), 'format' => 'money'],
                ['label' => 'Reembolsos', 'value' => (clone $query)->where('status', 'REFUNDED')->count()],
            ],
            'link' => '#clients',
        ];
    }

    private function contactQuery(): Builder
    {
        $query = DB::table('contacts')->whereNull('deleted_at');
        $this->scopePartner($query);
        if ($this->context->role === 'ADVISOR') {
            $query->where('assigned_advisor_id', $this->context->userId);
        } elseif ($this->context->role === 'TEAM_LEADER') {
            (new TeamAccessScope($this->context))->applyToContacts($query);
        }
        return $query;
    }

    private function applyVisibleContact(Builder $query, string $contactColumn): void
    {
        if (!in_array($this->context->role, ['ADVISOR', 'TEAM_LEADER'], true)) {
            return;
        }

        $query->whereExists(function (Builder $contact) use ($contactColumn): void {
            $contact->selectRaw('1')
                ->from('contacts')
                ->whereColumn('contacts.id', $contactColumn);
            $this->scopeContactBuilder($contact);
        });
    }

    private function scopeContactBuilder(Builder $contact): void
    {
        if ($this->context->partnerId !== null) {
            $contact->where('contacts.partner_id', $this->context->partnerId);
        }
        if ($this->context->role === 'ADVISOR') {
            $contact->where('contacts.assigned_advisor_id', $this->context->userId);
            return;
        }
        if ($this->context->role === 'TEAM_LEADER') {
            $scope = new TeamAccessScope($this->context);
            $teamIds = $scope->teamIds();
            $userIds = $scope->memberUserIds();
            if ($teamIds === [] && $userIds === []) {
                $contact->whereRaw('1 = 0');
                return;
            }
            $contact->where(function (Builder $visibility) use ($teamIds, $userIds): void {
                if ($teamIds !== []) $visibility->whereIn('contacts.origin_team_id', $teamIds);
                if ($userIds !== []) {
                    $method = $teamIds === [] ? 'whereIn' : 'orWhereIn';
                    $visibility->{$method}('contacts.assigned_advisor_id', $userIds);
                }
            });
        }
    }

    private function scopePartner(Builder $query, string $column = 'partner_id'): void
    {
        if ($this->context->partnerId !== null) {
            $query->where($column, $this->context->partnerId);
        }
    }

    private function layoutId(): string
    {
        return 'DLY_'.strtoupper(substr(hash('sha256', $this->context->userId), 0, 28));
    }
}
