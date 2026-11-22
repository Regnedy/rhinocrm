<?php
// Archivo: app/Modules/Partners/Domain/Entities/Partner.php

namespace App\Modules\Partners\Domain\Entities;

use DateTimeImmutable;

/**
 * Entidad de Dominio Pura (DDD). Totalmente encapsulada.
 * Representa a un Socio Comercial / Inquilino.
 */
class Partner
{
    public function __construct(
        private string $id,
        private string $planId,
        private string $businessName,
        private string $country,
        private string $status,
        private DateTimeImmutable $createdAt = new DateTimeImmutable(),
        private ?DateTimeImmutable $deletedAt = null
    ) {}
    
    // Getters inmutables
    public function getId(): string { return $this->id; }
    public function getPlanId(): string { return $this->planId; }
    public function getBusinessName(): string { return $this->businessName; }
    public function getCountry(): string { return $this->country; }
    public function getStatus(): string { return $this->status; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getDeletedAt(): ?DateTimeImmutable { return $this->deletedAt; }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE' && $this->deletedAt === null;
    }

    /**
     * Mutación Funcional: Suspende al socio.
     */
    public function suspend(): void
    {
        $this->status = 'SUSPENDED';
    }
}