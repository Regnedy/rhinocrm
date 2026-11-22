<?php

namespace App\Modules\Sales\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class SaleRefundedEvent
{
    use Dispatchable;

    public function __construct(
        public readonly string $eventId,
        public readonly string $partnerId,
        public readonly string $saleId,
        public readonly string $contactId,
        public readonly string $advisorId,
        public readonly ?string $originDeviceId,
        public readonly int $totalRevenueMinor,
        public readonly int $totalProfitMinor,
        public readonly string $currency,
        public readonly string $refundedAt,
        public readonly array $items,
        public readonly int $version = 1,
    ) {}
}
