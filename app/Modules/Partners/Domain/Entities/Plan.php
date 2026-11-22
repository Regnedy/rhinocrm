<?php
// Archivo: app/Modules/Partners/Domain/Entities/Plan.php

namespace App\Modules\Partners\Domain\Entities;

/**
 * Entidad de Dominio Pura. Representa un nivel de suscripción del SaaS.
 * Es inmutable por diseño ya que los planes son catálogos de lectura.
 */
readonly class Plan
{
    public function __construct(
        private string $id,
        private string $name,
        private int $maxUsers,
        private int $maxDevices,
        private int $maxMeasurements
    ) {}

    public function getId(): string { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getMaxUsers(): int { return $this->maxUsers; }
    public function getMaxDevices(): int { return $this->maxDevices; }
    public function getMaxMeasurements(): int { return $this->maxMeasurements; }
}