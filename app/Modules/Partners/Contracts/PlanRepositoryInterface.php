<?php
namespace App\Modules\Partners\Contracts;
use App\Modules\Partners\Domain\Entities\Plan;
interface PlanRepositoryInterface { public function find(string $id): ?Plan; }
