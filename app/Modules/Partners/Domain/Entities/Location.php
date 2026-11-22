<?php
// Archivo: app/Modules/Partners/Domain/Entities/Location.php

namespace App\Modules\Partners\Domain\Entities;

use DateTimeImmutable;

class Location
{
    public function __construct(
        private string $id,
        private string $partnerId,
        private string $name,
        private string $city,
        private string $state,
        private string $status,
        private DateTimeImmutable $createdAt = new DateTimeImmutable(),
        private ?DateTimeImmutable $deletedAt = null
    ) {}

    public function getId(): string { return $this->id; }
    public function getPartnerId(): string { return $this->partnerId; }
    public function getName(): string { return $this->name; }
    public function getCity(): string { return $this->city; }
    public function getState(): string { return $this->state; }
    public function getStatus(): string { return $this->status; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getDeletedAt(): ?DateTimeImmutable { return $this->deletedAt; }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE' && $this->deletedAt === null;
    }
}