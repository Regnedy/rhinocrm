<?php

namespace App\Modules\Workflows\Repositories;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Workflows\Contracts\WorkflowExecutionRepositoryInterface;
use App\Modules\Workflows\Domain\Entities\WorkflowExecution;
use App\Support\Database\GuardsTenant;
use App\Support\Database\SqlValue;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class SqlWorkflowExecutionRepository implements WorkflowExecutionRepositoryInterface
{
    use GuardsTenant;

    public function __construct(private readonly UserContextDTO $context) {}

    public function find(string $id): ?WorkflowExecution
    {
        $row = DB::table('workflow_executions')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }
        $this->guardTenant((string) $row->partner_id);
        return $this->map($row);
    }

    public function save(WorkflowExecution $execution): void
    {
        $this->guardTenant($execution->getPartnerId());
        DB::table('workflow_executions')->updateOrInsert(['id' => $execution->getId()], [
            'workflow_id' => $execution->getWorkflowId(),
            'workflow_version' => $execution->getWorkflowVersion(),
            'partner_id' => $execution->getPartnerId(),
            'contact_id' => $execution->getContactId(),
            'trigger_event_id' => $execution->getTriggerEventId(),
            'current_node_id' => $execution->getCurrentNodeId(),
            'status' => $execution->getStatus(),
            'nodes_snapshot' => SqlValue::encode($execution->getNodesSnapshot()),
            'completed_nodes' => SqlValue::encode($execution->getCompletedNodes()),
            'resume_at' => $execution->getResumeAt()?->format('Y-m-d H:i:s'),
            'failure_reason' => $execution->getFailureReason(),
            'created_at' => SqlValue::timestamp($execution->getCreatedAt()),
            'updated_at' => $execution->getUpdatedAt()?->format('Y-m-d H:i:s') ?? now(),
        ]);
    }

    public function findActiveForContact(string $contactId): array
    {
        $partnerId = $this->context->partnerId ?? throw new \RuntimeException('Contexto sin partner.', 403);
        return DB::table('workflow_executions')
            ->where('partner_id', $partnerId)
            ->where('contact_id', $contactId)
            ->whereIn('status', ['RUNNING', 'PAUSED'])
            ->get()->map(fn (object $row): WorkflowExecution => $this->map($row))->all();
    }

    private function map(object $row): WorkflowExecution
    {
        return new WorkflowExecution(
            (string) $row->id,
            (string) $row->workflow_id,
            (int) $row->workflow_version,
            (string) $row->partner_id,
            (string) $row->contact_id,
            (string) $row->trigger_event_id,
            (string) ($row->current_node_id ?? ''),
            (string) $row->status,
            SqlValue::json($row->nodes_snapshot),
            SqlValue::json($row->completed_nodes),
            SqlValue::date($row->resume_at),
            $row->failure_reason !== null ? (string) $row->failure_reason : null,
            SqlValue::date($row->created_at, new DateTimeImmutable()) ?? new DateTimeImmutable(),
            SqlValue::date($row->updated_at),
        );
    }
}
