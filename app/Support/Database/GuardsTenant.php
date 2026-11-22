<?php

namespace App\Support\Database;

use App\Modules\Auth\Domain\Exceptions\TenantViolationException;

trait GuardsTenant
{
    private function guardTenant(?string $partnerId): void
    {
        if ($this->context->isSuperAdmin() || $this->context->isSystem()) {
            return;
        }
        if ($partnerId === null || $this->context->partnerId !== $partnerId) {
            throw new TenantViolationException($this->context->userId, (string) $partnerId);
        }
    }
}
