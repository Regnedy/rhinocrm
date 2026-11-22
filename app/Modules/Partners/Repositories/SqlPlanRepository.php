<?php

namespace App\Modules\Partners\Repositories;

use App\Modules\Partners\Contracts\PlanRepositoryInterface;
use App\Modules\Partners\Domain\Entities\Plan;
use Illuminate\Support\Facades\DB;

final class SqlPlanRepository implements PlanRepositoryInterface
{
    public function find(string $id): ?Plan
    {
        $row = DB::table('plans')->where('id', $id)->where('active', true)->first();
        return $row ? new Plan((string) $row->id, (string) $row->name, (int) $row->max_users, (int) $row->max_devices, (int) $row->max_measurements) : null;
    }
}
