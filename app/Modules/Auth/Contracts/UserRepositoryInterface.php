<?php

namespace App\Modules\Auth\Contracts;

use App\Modules\Auth\Domain\Entities\User;

interface UserRepositoryInterface
{
    public function find(string $id): ?User;
    public function findByEmail(string $email): ?User;
    public function save(User $user): void;

    /** @return User[] */
    public function findActiveAdvisors(string $partnerId, string $locationId): array;

    /** @return User[] */
    public function listForPartner(string $partnerId, int $limit = 200): array;
}
