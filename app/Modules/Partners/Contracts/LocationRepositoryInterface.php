<?php
namespace App\Modules\Partners\Contracts;
use App\Modules\Partners\Domain\Entities\Location;
interface LocationRepositoryInterface { public function find(string $id): ?Location; public function save(Location $location): void; }
