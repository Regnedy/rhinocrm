<?php

namespace App\Modules\IoT\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class MeasurementReceivedEvent
{
    use Dispatchable;

    public function __construct(
        public readonly string $eventId,
        public readonly string $partnerId,
        public readonly string $measurementId,
        public readonly int $version = 1,
    ) {}
}
