<?php
namespace App\Modules\Partners\Contracts;
use App\Modules\Partners\Domain\Entities\Partner;
interface PartnerRepositoryInterface { public function find(string $id): ?Partner; public function save(Partner $partner): void; }
