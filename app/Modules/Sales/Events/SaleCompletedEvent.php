<?php

namespace App\Modules\Sales\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class SaleCompletedEvent
{
    use Dispatchable;

    public function __construct(
        public readonly string $eventId,
        public readonly string $partnerId,
        public readonly string $saleId,
        public readonly string $contactId,
        public readonly string $advisorId,
        public readonly ?string $originDeviceId,
        public readonly string $saleType,
        public readonly int $totalRevenueMinor,
        public readonly int $totalProfitMinor,
        public readonly string $currency,
        public readonly string $closedAt,
        public readonly array $items,
        public readonly ?string $originCampaignId = null,
        public readonly int $version = 1,
    ) {}
}
