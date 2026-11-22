<?php

namespace App\Modules\Workflows\Repositories;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Workflows\Contracts\WorkflowRepositoryInterface;
use App\Modules\Workflows\Domain\Entities\Workflow;
use App\Support\Database\GuardsTenant;
use App\Support\Database\SqlValue;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class SqlWorkflowRepository implements WorkflowRepositoryInterface
{
    use GuardsTenant;

    public function __construct(private readonly UserContextDTO $context) {}

    public function find(string $id): ?Workflow
    {
        $row = DB::table('workflow_definitions')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }
        $this->guardTenant((string) $row->partner_id);
        return $this->map($row);
    }

    public function list(int $limit = 100): array
    {
        $partnerId = $this->context->partnerId ?? throw new \RuntimeException('Contexto sin partner.', 403);
        return DB::table('workflow_definitions')->where('partner_id', $partnerId)
            ->orderBy('name')->limit(min(max($limit, 1), 200))->get()
            ->map(fn (object $row): Workflow => $this->map($row))->all();
    }

    public function getActiveByTrigger(string $partnerId, string $triggerType): array
    {
        $this->guardTenant($partnerId);
        return DB::table('workflow_definitions')
            ->where('partner_id', $partnerId)
            ->where('trigger_event', $triggerType)
            ->where('is_active', true)
            ->get()->map(fn (object $row): Workflow => $this->map($row))->all();
    }

    public function save(Workflow $workflow): void
    {
        $this->guardTenant($workflow->getPartnerId());
        DB::table('workflow_definitions')->updateOrInsert(['id' => $workflow->getId()], [
            'partner_id' => $workflow->getPartnerId(),
            'name' => $workflow->getName(),
            'version' => $workflow->getVersion(),
            'trigger_event' => $workflow->getTriggerEvent(),
            'trigger_conditions' => SqlValue::encode($workflow->getTriggerConditions()),
            'nodes' => SqlValue::encode($workflow->getNodes()),
            'entry_node_id' => $workflow->getEntryNodeId(),
            'cancel_on_stage_change' => $workflow->isCancelableOnStageChange(),
            'is_active' => $workflow->isActive(),
            'created_at' => SqlValue::timestamp($workflow->getCreatedAt()),
            'updated_at' => now(),
        ]);
    }

    private function map(object $row): Workflow
    {
        return new Workflow(
            (string) $row->id,
            (string) $row->partner_id,
            (string) $row->name,
            (int) $row->version,
            (string) $row->trigger_event,
            SqlValue::json($row->trigger_conditions),
            SqlValue::json($row->nodes),
            (string) $row->entry_node_id,
            (bool) $row->cancel_on_stage_change,
            (bool) $row->is_active,
            SqlValue::date($row->created_at, new DateTimeImmutable()) ?? new DateTimeImmutable(),
        );
    }
}
