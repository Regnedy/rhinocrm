<?php

namespace App\Modules\Partners\Services;

use App\Modules\Partners\Contracts\PlanRepositoryInterface;
use Illuminate\Support\Facades\DB;

final class PlanLimitService
{
    public function __construct(private readonly PlanRepositoryInterface $plans) {}

    public function assertCanAddUser(string $partnerId): void
    {
        [$planId, $plan] = $this->resolvePlan($partnerId);
        $current = DB::table('users')->where('partner_id', $partnerId)->whereNull('deleted_at')->count();
        if ($current >= $plan->getMaxUsers()) {
            throw new \RuntimeException("El plan {$planId} alcanzó el límite de usuarios.", 409);
        }
    }

    public function assertCanAddDevice(string $partnerId): void
    {
        [$planId, $plan] = $this->resolvePlan($partnerId);
        $current = DB::table('devices')->where('partner_id', $partnerId)->whereNull('deleted_at')->count();
        if ($current >= $plan->getMaxDevices()) {
            throw new \RuntimeException("El plan {$planId} alcanzó el límite de dispositivos.", 409);
        }
    }

    public function measurementLimit(string $partnerId): int
    {
        [, $plan] = $this->resolvePlan($partnerId);
        return $plan->getMaxMeasurements();
    }

    private function resolvePlan(string $partnerId): array
    {
        $partner = DB::table('partners')->where('id', $partnerId)->whereNull('deleted_at')->first();
        if ($partner === null) {
            throw new \RuntimeException('Socio no encontrado.', 404);
        }
        $planId = (string) $partner->plan_id;
        $plan = $this->plans->find($planId);
        if ($plan === null) {
            throw new \RuntimeException('Plan del socio no encontrado.', 409);
        }
        return [$planId, $plan];
    }
}
