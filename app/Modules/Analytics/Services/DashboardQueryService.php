<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class DashboardQueryService
{
    public function __construct(private readonly UserContextDTO $context) {}

    public function getPartnerDashboard(string $period): array
    {
        $partnerId = $this->context->partnerId ?? throw new \RuntimeException('Contexto sin partner.');
        return Cache::remember("dashboard:partner:{$partnerId}:{$period}", 120, fn (): array => $this->partnerData($partnerId, $period));
    }

    public function getAdvisorDashboard(string $period): array
    {
        $partnerId = $this->context->partnerId ?? throw new \RuntimeException('Contexto sin partner.');
        [$start, $end] = $this->periodRange($period);
        $sales = DB::table('sales')->where('partner_id', $partnerId)->where('advisor_id', $this->context->userId)
            ->whereBetween('closed_at', [$start, $end])->selectRaw("COUNT(CASE WHEN status='COMPLETED' THEN 1 END) sales_closed")
            ->selectRaw("COALESCE(SUM(CASE WHEN status='COMPLETED' THEN total_revenue_minor ELSE 0 END),0) revenue_generated_minor")->first();
        return [
            'period' => $period,
            'my_performance' => [
                'prospects_generated' => DB::table('contacts')->where('partner_id', $partnerId)->where('generated_by_user_id', $this->context->userId)->whereBetween('created_at', [$start, $end])->count(),
                'prospects_assigned' => DB::table('contacts')->where('partner_id', $partnerId)->where('assigned_advisor_id', $this->context->userId)->whereBetween('created_at', [$start, $end])->count(),
                'sales_closed' => (int) ($sales->sales_closed ?? 0),
                'revenue_generated_minor' => (int) ($sales->revenue_generated_minor ?? 0),
            ],
            'sla_alerts' => [
                'overdue_tasks' => DB::table('tasks')->where('partner_id', $partnerId)->where('assigned_user_id', $this->context->userId)->where('status', 'PENDING')->where('due_at', '<', now())->count(),
            ],
        ];
    }

    public function getSuperAdminDashboard(string $period): array
    {
        $partners = DB::table('partners')->whereNull('deleted_at')->orderBy('business_name')->get();
        return [
            'period' => $period,
            'totals' => [
                'partners' => $partners->count(),
                'active_partners' => $partners->where('status', 'ACTIVE')->count(),
                'suspended_partners' => $partners->where('status', 'SUSPENDED')->count(),
                'active_sessions' => DB::table('api_sessions')->whereNull('revoked_at')->where('expires_at', '>', now())->count(),
                'active_devices' => DB::table('devices')->where('status', 'ACTIVE')->whereNull('deleted_at')->count(),
            ],
            'partners' => $partners->map(fn (object $partner): array => [
                'id' => $partner->id,
                'business_name' => $partner->business_name,
                'status' => $partner->status,
                'summary' => $this->partnerData((string) $partner->id, $period)['financials'],
            ])->all(),
        ];
    }

    private function partnerData(string $partnerId, string $period): array
    {
        [$start, $end] = $this->periodRange($period);
        $financials = DB::table('sales')->where('partner_id', $partnerId)->whereBetween('closed_at', [$start, $end])
            ->selectRaw("COUNT(CASE WHEN status='COMPLETED' THEN 1 END) sales_count")
            ->selectRaw("COUNT(CASE WHEN status='REFUNDED' THEN 1 END) refund_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status='COMPLETED' THEN total_revenue_minor ELSE 0 END),0) total_revenue_minor")
            ->selectRaw("COALESCE(SUM(CASE WHEN status='COMPLETED' THEN total_profit_minor ELSE 0 END),0) total_profit_minor")
            ->first();

        $advisors = DB::table('users as u')->where('u.partner_id', $partnerId)->whereIn('u.role', ['ADVISOR', 'TEAM_LEADER', 'LOCATION_MANAGER'])->where('u.status', 'ACTIVE')
            ->leftJoin('sales as s', function ($join) use ($start, $end): void { $join->on('s.advisor_id', '=', 'u.id')->whereBetween('s.closed_at', [$start, $end]); })
            ->select('u.id', 'u.name')->selectRaw("COUNT(CASE WHEN s.status='COMPLETED' THEN 1 END) sales_closed")
            ->selectRaw("COALESCE(SUM(CASE WHEN s.status='COMPLETED' THEN s.total_revenue_minor ELSE 0 END),0) revenue_generated_minor")
            ->groupBy('u.id', 'u.name')->orderByDesc('revenue_generated_minor')->get();

        $devices = DB::table('sales as s')->join('devices as d', 'd.id', '=', 's.origin_device_id')
            ->where('s.partner_id', $partnerId)->whereBetween('s.closed_at', [$start, $end])->where('s.status', 'COMPLETED')
            ->select('d.id', 'd.mac_address')->selectRaw('COUNT(*) sales_count')->selectRaw('SUM(s.total_revenue_minor) sales_amount_minor')
            ->selectRaw('SUM(s.total_profit_minor) profit_generated_minor')->groupBy('d.id', 'd.mac_address')->orderByDesc('profit_generated_minor')->limit(5)->get();

        $products = DB::table('sale_items as i')->join('sales as s', 's.id', '=', 'i.sale_id')
            ->where('s.partner_id', $partnerId)->whereBetween('s.closed_at', [$start, $end])->where('s.status', 'COMPLETED')
            ->select('i.product_id', 'i.product_name_snapshot')->selectRaw('SUM(i.quantity) units_sold')->selectRaw('SUM(i.subtotal_revenue_minor) revenue_minor')
            ->groupBy('i.product_id', 'i.product_name_snapshot')->orderByDesc('units_sold')->limit(10)->get();

        $funnel = DB::table('contacts')->where('partner_id', $partnerId)->where('status', 'ACTIVE')->whereNull('deleted_at')
            ->select('sales_stage', DB::raw('COUNT(*) total'))->groupBy('sales_stage')->pluck('total', 'sales_stage')->all();

        return [
            'period' => $period,
            'financials' => [
                'sales_count' => (int) ($financials->sales_count ?? 0),
                'refund_count' => (int) ($financials->refund_count ?? 0),
                'total_revenue_minor' => (int) ($financials->total_revenue_minor ?? 0),
                'total_profit_minor' => (int) ($financials->total_profit_minor ?? 0),
            ],
            'top_devices_roi' => $devices,
            'top_products' => $products,
            'advisors_performance' => $advisors,
            'funnel' => $funnel,
        ];
    }

    /** @return array{0:string,1:string} */
    private function periodRange(string $period): array
    {
        $start = \Carbon\CarbonImmutable::createFromFormat('Y-m', $period)->startOfMonth();
        return [$start->toDateTimeString(), $start->endOfMonth()->endOfDay()->toDateTimeString()];
    }
}
