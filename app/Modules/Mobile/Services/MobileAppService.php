<?php

namespace App\Modules\Mobile\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Billing\Services\BillingAccessService;
use App\Modules\Campaigns\Services\CampaignInvitationService;
use App\Modules\Campaigns\Services\CampaignService;
use App\Modules\CRM\Services\ContactManagementService;
use App\Support\Database\SqlValue;
use App\Support\Database\TeamAccessScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class MobileAppService
{
    public function __construct(
        private readonly UserContextDTO $context,
        private readonly BillingAccessService $billing,
        private readonly ContactManagementService $contacts,
        private readonly CampaignService $campaigns,
        private readonly CampaignInvitationService $invitations,
    ) {}

    /** @return array<string,mixed> */
    public function appConfig(): array
    {
        return [
            'schema_version' => 1,
            'app' => [
                'name' => 'Rhino CRM',
                'identifier' => 'com.activa7.rhinocrm.mobile',
                'minimum_supported_version' => (string) config('billing.mobile.minimum_supported_version', '1.0.0'),
                'latest_version' => (string) config('billing.mobile.latest_version', '1.0.0'),
                'maintenance_mode' => (bool) config('billing.mobile.maintenance_mode', false),
                'support_email' => (string) config('billing.mobile.support_email', 'soporte@rhinocrm.app'),
            ],
            'billing' => $this->billing->configuration(),
            'features' => [
                'dashboard' => true,
                'campaign_invitations' => Schema::hasTable('campaign_invitations'),
                'collaborative_events' => Schema::hasTable('campaign_activity'),
                'contacts' => Schema::hasTable('contacts'),
                'measurement_dashboard' => class_exists(\App\Modules\CRM\Services\ContactMeasurementPresentationService::class),
                'nutrition' => Schema::hasTable('nutrition_plan_versions'),
                'tasks' => Schema::hasTable('tasks'),
                'sales' => Schema::hasTable('sales'),
                'push_registration' => Schema::hasTable('mobile_app_devices'),
                'payments' => false,
            ],
            'api' => [
                'version' => 'v1',
                'contracts' => [
                    'mobile_bootstrap' => 'rhino.mobile.bootstrap.v1',
                    'measurement_dashboard' => 'rhino.contact.measurement-dashboard',
                    'billing_access' => 'rhino.billing.access.v1',
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function bootstrap(): array
    {
        $access = $this->billing->access($this->context);
        $user = DB::table('users')->where('id', $this->context->userId)->first(['id', 'name', 'email', 'role', 'status', 'partner_id']);
        $partner = $this->context->partnerId !== null
            ? DB::table('partners')->where('id', $this->context->partnerId)->first(['id', 'business_name', 'partner_number', 'status'])
            : null;

        return [
            'schema_version' => 1,
            'contract' => 'rhino.mobile.bootstrap.v1',
            'access' => $access,
            'config' => $this->appConfig(),
            'session' => [
                'user' => $user === null ? null : [
                    'id' => (string) $user->id,
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                    'role' => (string) $user->role,
                    'status' => (string) $user->status,
                    'permissions' => $this->context->permissions,
                ],
                'partner' => $partner === null ? null : [
                    'id' => (string) $partner->id,
                    'name' => (string) $partner->business_name,
                    'partner_number' => $partner->partner_number !== null ? (string) $partner->partner_number : null,
                    'status' => (string) $partner->status,
                ],
            ],
            'dashboard' => $this->dashboard(),
            'notifications' => $this->notifications(20),
        ];
    }

    /** @return array<string,mixed> */
    public function dashboard(): array
    {
        if ($this->context->isSuperAdmin()) return $this->superAdminDashboard();
        $partnerId = $this->partnerId();
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $contactScope = $this->contactScope();
        $contactsTotal = (clone $contactScope)->count();
        $newContacts = (clone $contactScope)->whereBetween('created_at', [$monthStart, $monthEnd])->count();
        $contactsWithMeasurements = (clone $contactScope)->where('total_measurements', '>', 0)->count();

        $tasks = Schema::hasTable('tasks')
            ? DB::table('tasks')->where('partner_id', $partnerId)->where('assigned_user_id', $this->context->userId)
            : null;
        $pendingTasks = $tasks === null ? 0 : (clone $tasks)->where('status', 'PENDING')->count();
        $overdueTasks = $tasks === null ? 0 : (clone $tasks)->where('status', 'PENDING')->where('due_at', '<', now())->count();

        $events = $this->campaigns->list(['from' => now()->subDays(60)->toAtomString()]);
        $activeEvents = count(array_filter($events, static fn (array $event): bool => ($event['status'] ?? null) === 'ACTIVE'));
        $upcomingEvents = count(array_filter($events, static fn (array $event): bool => in_array(($event['status'] ?? null), ['SCHEDULED', 'DRAFT'], true)));

        $pendingInvitations = count(array_filter(
            $this->invitations->inbox(),
            static fn (array $invitation): bool => ($invitation['status'] ?? null) === 'PENDING',
        ));

        $sales = ['count' => 0, 'revenue_minor' => 0, 'currency' => 'MXN'];
        if (Schema::hasTable('sales')) {
            $saleRow = DB::table('sales')
                ->where('partner_id', $partnerId)
                ->where('advisor_id', $this->context->userId)
                ->where('status', 'COMPLETED')
                ->whereBetween('closed_at', [$monthStart, $monthEnd])
                ->selectRaw('COUNT(*) as total')
                ->selectRaw('COALESCE(SUM(total_revenue_minor), 0) as revenue')
                ->first();
            $sales = [
                'count' => (int) ($saleRow->total ?? 0),
                'revenue_minor' => (int) ($saleRow->revenue ?? 0),
                'currency' => (string) (DB::table('sales')->where('partner_id', $partnerId)->latest('closed_at')->value('currency_snapshot') ?? 'MXN'),
            ];
        }

        $nutritionPlans = 0;
        if (Schema::hasTable('nutrition_plan_versions') && Schema::hasColumn('nutrition_plan_versions', 'created_by_user_id')) {
            $nutritionPlans = DB::table('nutrition_plan_versions')
                ->where('partner_id', $partnerId)
                ->where('created_by_user_id', $this->context->userId)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->count();
        }

        return [
            'generated_at' => now()->toAtomString(),
            'greeting' => $this->greeting(),
            'metrics' => [
                'contacts_total' => $contactsTotal,
                'contacts_new_this_month' => $newContacts,
                'contacts_with_measurements' => $contactsWithMeasurements,
                'tasks_pending' => $pendingTasks,
                'tasks_overdue' => $overdueTasks,
                'events_active' => $activeEvents,
                'events_upcoming' => $upcomingEvents,
                'invitations_pending' => $pendingInvitations,
                'nutrition_plans_this_month' => $nutritionPlans,
                'my_sales_this_month' => $sales,
            ],
            'recent_contacts' => $this->contacts->list(['limit' => 6])['items'] ?? [],
            'upcoming_tasks' => $this->upcomingTasks(6),
            'active_events' => array_values(array_slice(array_filter(
                $events,
                static fn (array $event): bool => in_array(($event['status'] ?? null), ['ACTIVE', 'SCHEDULED'], true),
            ), 0, 5)),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function notifications(int $limit = 30): array
    {
        $items = [];

        if (Schema::hasTable('campaign_invitations')) {
            foreach ($this->invitations->inbox() as $invitation) {
                if (($invitation['status'] ?? null) !== 'PENDING') continue;
                $items[] = [
                    'id' => 'INVITATION_'.(string) $invitation['id'],
                    'type' => 'CAMPAIGN_INVITATION',
                    'title' => 'Invitación a evento',
                    'body' => (string) ($invitation['inviter_name'] ?? 'Un colaborador').' te invitó a '.(string) ($invitation['campaign_name'] ?? 'un evento').'.',
                    'created_at' => $invitation['created_at'] ?? null,
                    'read' => false,
                    'action' => [
                        'screen' => 'InvitationDetail',
                        'params' => ['invitation_id' => (string) $invitation['id']],
                    ],
                    'data' => $invitation,
                ];
            }
        }

        if (Schema::hasTable('tasks')) {
            $taskRows = DB::table('tasks as task')
                ->leftJoin('contacts as contact', 'contact.id', '=', 'task.contact_id')
                ->where('task.assigned_user_id', $this->context->userId)
                ->where('task.status', 'PENDING')
                ->where('task.due_at', '<=', now()->addDays(2))
                ->select('task.*', 'contact.name as contact_name')
                ->orderBy('task.due_at')
                ->limit(50)
                ->get();

            foreach ($taskRows as $task) {
                $overdue = now()->greaterThan($task->due_at);
                $items[] = [
                    'id' => 'TASK_'.(string) $task->id,
                    'type' => $overdue ? 'TASK_OVERDUE' : 'TASK_DUE',
                    'title' => $overdue ? 'Seguimiento vencido' : 'Seguimiento próximo',
                    'body' => (string) $task->title.($task->contact_name ? ' · '.$task->contact_name : ''),
                    'created_at' => $task->created_at,
                    'due_at' => $task->due_at,
                    'read' => false,
                    'action' => [
                        'screen' => 'TaskDetail',
                        'params' => ['task_id' => (string) $task->id],
                    ],
                    'data' => (array) $task,
                ];
            }
        }

        usort($items, static function (array $left, array $right): int {
            return strcmp((string) ($right['created_at'] ?? $right['due_at'] ?? ''), (string) ($left['created_at'] ?? $left['due_at'] ?? ''));
        });

        return array_slice($items, 0, min(max($limit, 1), 100));
    }

    /** @return array<string,mixed> */
    public function registerDevice(array $data): array
    {
        if (!Schema::hasTable('mobile_app_devices')) {
            throw new \RuntimeException('La migración de la aplicación móvil no está instalada.', 409);
        }

        $installationId = trim((string) $data['installation_id']);
        $existing = DB::table('mobile_app_devices')->where('installation_id', $installationId)->first();
        $id = $existing?->id !== null ? (string) $existing->id : 'MOB_'.strtoupper((string) Str::ulid());
        $values = [
            'user_id' => $this->context->userId,
            'partner_id' => $this->context->partnerId,
            'platform' => strtoupper((string) ($data['platform'] ?? 'ANDROID')),
            'app_version' => $data['app_version'] ?? null,
            'device_name' => $data['device_name'] ?? null,
            'device_model' => $data['device_model'] ?? null,
            'push_token' => $data['push_token'] ?? null,
            'push_provider' => $data['push_provider'] ?? null,
            'status' => 'ACTIVE',
            'last_seen_at' => now(),
            'capabilities' => SqlValue::encode((array) ($data['capabilities'] ?? [])),
            'updated_at' => now(),
        ];

        if ($existing === null) {
            DB::table('mobile_app_devices')->insert($values + [
                'id' => $id,
                'installation_id' => $installationId,
                'created_at' => now(),
            ]);
        } else {
            DB::table('mobile_app_devices')->where('id', $id)->update($values);
        }

        $row = DB::table('mobile_app_devices')->where('id', $id)->first();
        return [
            'id' => (string) $row->id,
            'installation_id' => (string) $row->installation_id,
            'platform' => (string) $row->platform,
            'app_version' => $row->app_version,
            'push_registered' => $row->push_token !== null && $row->push_token !== '',
            'status' => (string) $row->status,
            'last_seen_at' => $row->last_seen_at,
        ];
    }

    /** @return array<string,mixed> */
    private function superAdminDashboard(): array
    {
        return [
            'generated_at' => now()->toAtomString(),
            'greeting' => $this->greeting(),
            'metrics' => [
                'partners_total' => Schema::hasTable('partners') ? DB::table('partners')->whereNull('deleted_at')->count() : 0,
                'partners_active' => Schema::hasTable('partners') ? DB::table('partners')->where('status', 'ACTIVE')->whereNull('deleted_at')->count() : 0,
                'contacts_total' => Schema::hasTable('contacts') ? DB::table('contacts')->whereNull('deleted_at')->count() : 0,
                'events_active' => Schema::hasTable('campaigns') ? DB::table('campaigns')->where('status', 'ACTIVE')->whereNull('deleted_at')->count() : 0,
                'registered_mobile_devices' => Schema::hasTable('mobile_app_devices') ? DB::table('mobile_app_devices')->where('status', 'ACTIVE')->count() : 0,
            ],
            'recent_contacts' => [],
            'upcoming_tasks' => [],
            'active_events' => [],
        ];
    }

    private function contactScope(): Builder
    {
        $query = DB::table('contacts')
            ->where('partner_id', $this->partnerId())
            ->whereNull('deleted_at')
            ->where('status', 'ACTIVE');

        if ($this->context->role === 'ADVISOR') {
            $query->where('assigned_advisor_id', $this->context->userId);
        } elseif ($this->context->role === 'TEAM_LEADER') {
            (new TeamAccessScope($this->context))->applyToContacts($query);
        }

        return $query;
    }

    /** @return array<int,array<string,mixed>> */
    private function upcomingTasks(int $limit): array
    {
        if (!Schema::hasTable('tasks')) return [];

        return DB::table('tasks as task')
            ->leftJoin('contacts as contact', 'contact.id', '=', 'task.contact_id')
            ->where('task.assigned_user_id', $this->context->userId)
            ->where('task.status', 'PENDING')
            ->select(
                'task.id', 'task.contact_id', 'contact.name as contact_name', 'task.type',
                'task.title', 'task.status', 'task.due_at', 'task.created_at',
            )
            ->orderBy('task.due_at')
            ->limit(min(max($limit, 1), 50))
            ->get()->map(static fn (object $task): array => [
                'id' => (string) $task->id,
                'contact_id' => (string) $task->contact_id,
                'contact_name' => $task->contact_name,
                'type' => (string) $task->type,
                'title' => (string) $task->title,
                'status' => (string) $task->status,
                'due_at' => $task->due_at,
                'overdue' => now()->greaterThan($task->due_at),
                'created_at' => $task->created_at,
            ])->all();
    }

    private function greeting(): string
    {
        $hour = (int) now()->format('G');
        if ($hour < 12) return 'Buenos días';
        if ($hour < 19) return 'Buenas tardes';
        return 'Buenas noches';
    }

    private function partnerId(): string
    {
        return $this->context->partnerId ?? throw new \RuntimeException('La aplicación móvil requiere un usuario asociado a un socio.', 403);
    }
}
