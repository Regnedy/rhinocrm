<?php
// Archivo: app/Modules/Auth/Domain/Entities/User.php

namespace App\Modules\Auth\Domain\Entities;

use DateTimeImmutable;

/**
 * Entidad de Dominio Pura. Representa a un Empleado/Asesor en el CRM.
 */
class User
{
    public function __construct(
        private string $id, // Firebase Auth UID
        private ?string $partnerId,
        private string $role,
        private string $name,
        private string $email,
        private string $status,
        private ?string $locationId = null,
        private DateTimeImmutable $createdAt = new DateTimeImmutable(),
        private ?DateTimeImmutable $deletedAt = null
    ) {}

    public function getId(): string { return $this->id; }
    public function getPartnerId(): ?string { return $this->partnerId; }
    public function getRole(): string { return $this->role; }
    public function getName(): string { return $this->name; }
    public function getEmail(): string { return $this->email; }
    public function getStatus(): string { return $this->status; }
    public function getLocationId(): ?string { return $this->locationId; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getDeletedAt(): ?DateTimeImmutable { return $this->deletedAt; }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE' && $this->deletedAt === null;
    }

    public function suspend(): void
    {
        $this->status = 'SUSPENDED';
    }
}