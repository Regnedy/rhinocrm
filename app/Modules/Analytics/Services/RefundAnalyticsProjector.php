<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Auth\Domain\Exceptions\TenantViolationException;
use App\Modules\Sales\Events\SaleRefundedEvent;
use Illuminate\Support\Facades\DB;

final class RefundAnalyticsProjector
{
    public function __construct(private readonly UserContextDTO $context) {}

    public function project(SaleRefundedEvent $event): void
    {
        if ($this->context->partnerId !== $event->partnerId) {
            throw new TenantViolationException($this->context->userId, $event->partnerId);
        }
        $period = (new \DateTimeImmutable($event->refundedAt))->format('Y-m');
        $consumerKey = "refund_analytics:v{$event->version}:{$event->eventId}";
        $hash = hash('sha256', $consumerKey);

        DB::transaction(function () use ($event, $period, $consumerKey, $hash): void {
            if (DB::table('event_consumptions')->where('consumer_key_hash', $hash)->lockForUpdate()->exists()) {
                return;
            }
            $teamId = DB::table('sales')->where('id', $event->saleId)->value('team_id');

            $this->ensure('partner_analytics', ['partner_id' => $event->partnerId, 'period' => $period], $event->currency);
            DB::table('partner_analytics')->where('partner_id', $event->partnerId)->where('period', $period)->update([
                'refund_count' => DB::raw('refund_count + 1'),
                'refunded_revenue_minor' => DB::raw('refunded_revenue_minor + '.(int) $event->totalRevenueMinor),
                'refunded_profit_minor' => DB::raw('refunded_profit_minor + '.(int) $event->totalProfitMinor),
                'total_revenue_minor' => DB::raw('GREATEST(total_revenue_minor - '.(int) $event->totalRevenueMinor.', 0)'),
                'total_profit_minor' => DB::raw('GREATEST(total_profit_minor - '.(int) $event->totalProfitMinor.', 0)'),
                'updated_at' => now(),
            ]);

            $this->ensure('user_analytics', ['partner_id' => $event->partnerId, 'user_id' => $event->advisorId, 'period' => $period], $event->currency);
            DB::table('user_analytics')->where('partner_id', $event->partnerId)->where('user_id', $event->advisorId)->where('period', $period)->update([
                'refund_count' => DB::raw('refund_count + 1'),
                'revenue_generated_minor' => DB::raw('revenue_generated_minor - '.(int) $event->totalRevenueMinor),
                'updated_at' => now(),
            ]);

            if ($teamId !== null) {
                $this->ensure('team_analytics', ['partner_id' => $event->partnerId, 'team_id' => (string) $teamId, 'period' => $period], $event->currency);
                DB::table('team_analytics')->where('partner_id', $event->partnerId)->where('team_id', $teamId)->where('period', $period)->update([
                    'refund_count' => DB::raw('refund_count + 1'),
                    'revenue_generated_minor' => DB::raw('revenue_generated_minor - '.(int) $event->totalRevenueMinor),
                    'updated_at' => now(),
                ]);
            }

            if ($event->originDeviceId !== null) {
                $this->ensure('device_analytics', ['partner_id' => $event->partnerId, 'device_id' => $event->originDeviceId, 'period' => $period], $event->currency);
                DB::table('device_analytics')->where('partner_id', $event->partnerId)->where('device_id', $event->originDeviceId)->where('period', $period)->update([
                    'refund_count' => DB::raw('refund_count + 1'),
                    'sales_amount_minor' => DB::raw('sales_amount_minor - '.(int) $event->totalRevenueMinor),
                    'profit_generated_minor' => DB::raw('profit_generated_minor - '.(int) $event->totalProfitMinor),
                    'updated_at' => now(),
                ]);
            }

            foreach ($event->items as $item) {
                $productId = (string) $item['product_id'];
                $this->ensure('product_analytics', ['partner_id' => $event->partnerId, 'product_id' => $productId, 'period' => $period], $event->currency);
                DB::table('product_analytics')->where('partner_id', $event->partnerId)->where('product_id', $productId)->where('period', $period)->update([
                    'units_sold' => DB::raw('units_sold - '.(int) $item['quantity']),
                    'units_returned' => DB::raw('units_returned + '.(int) $item['quantity']),
                    'revenue_minor' => DB::raw('revenue_minor - '.(int) $item['revenue_minor']),
                    'profit_minor' => DB::raw('profit_minor - '.(int) $item['profit_minor']),
                    'updated_at' => now(),
                ]);
            }

            DB::table('event_consumptions')->insert([
                'consumer_key_hash' => $hash,
                'consumer' => 'refund_analytics',
                'consumer_key' => $consumerKey,
                'event_id' => $event->eventId,
                'partner_id' => $event->partnerId,
                'result' => 'PROJECTED',
                'processed_at' => now(),
            ]);
        }, 3);
    }

    private function ensure(string $table, array $keys, string $currency): void
    {
        if (!DB::table($table)->where($keys)->exists()) {
            DB::table($table)->insertOrIgnore(array_merge($keys, ['currency' => $currency, 'created_at' => now(), 'updated_at' => now()]));
        }
    }
}
