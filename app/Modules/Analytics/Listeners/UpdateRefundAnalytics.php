<?php

namespace App\Modules\Analytics\Listeners;

use App\Modules\Analytics\Services\RefundAnalyticsProjector;
use App\Modules\Auth\Services\TenantContextRunner;
use App\Modules\Sales\Events\SaleRefundedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;

final class UpdateRefundAnalytics implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly TenantContextRunner $runner) {}

    public function handle(SaleRefundedEvent $event): void
    {
        $this->runner->runAsSystem(
            $event->partnerId,
            fn () => app(RefundAnalyticsProjector::class)->project($event),
        );
    }
}
