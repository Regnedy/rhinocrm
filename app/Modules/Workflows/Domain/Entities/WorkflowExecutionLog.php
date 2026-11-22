<?php
// Archivo: app/Modules/Workflows/Domain/Entities/WorkflowExecutionLog.php

namespace App\Modules\Workflows\Domain\Entities;

use DateTimeImmutable;

/**
 * Append-Only Log para auditar paso a paso qué hizo el motor.
 */
readonly class WorkflowExecutionLog
{
    public function __construct(
        public string $id,
        public string $executionId,
        public string $partnerId,
        public string $nodeId,
        public string $actionType,
        public string $status, // SUCCESS, FAILED
        public ?string $outputMessage,
        public DateTimeImmutable $timestamp = new DateTimeImmutable()
    ) {}
}