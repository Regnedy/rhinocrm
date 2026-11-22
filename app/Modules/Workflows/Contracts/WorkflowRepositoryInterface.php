<?php

namespace App\Modules\Workflows\Contracts;

use App\Modules\Workflows\Domain\Entities\Workflow;

interface WorkflowRepositoryInterface
{
    public function find(string $id): ?Workflow;

    /** @return Workflow[] */
    public function list(int $limit = 100): array;

    /** @return Workflow[] */
    public function getActiveByTrigger(string $partnerId, string $triggerType): array;

    public function save(Workflow $workflow): void;
}
