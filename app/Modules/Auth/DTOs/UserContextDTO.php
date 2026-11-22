<?php
// Archivo: app/Modules/Auth/DTOs/UserContextDTO.php

namespace App\Modules\Auth\DTOs;

readonly class UserContextDTO
{
    public function __construct(
        public string $userId,
        public ?string $partnerId, 
        public string $role,
        public ?string $locationId = null,
        public array $permissions = []
    ) {}

    public function isSuperAdmin(): bool { return $this->role === 'SUPER_ADMIN'; }
    public function isSystem(): bool { return in_array($this->role, ['SYSTEM_ACTOR', 'SYSTEM_WEBHOOK'], true); }
    public function hasPermission(string $permission): bool {
        if ($this->isSuperAdmin() || $this->isSystem()) return true;
        return in_array($permission, $this->permissions, true);
    }

    public static function asSystem(string $partnerId): self
    {
        return new self('SYSTEM', $partnerId, 'SYSTEM_ACTOR', null, ['*']);
    }
    
    public static function asWebhook(): self
    {
        return new self('SYSTEM_WEBHOOK', null, 'SYSTEM_WEBHOOK', null, ['*']);
    }
}