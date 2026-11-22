<?php

namespace App\Modules\Workflows\Repositories;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Workflows\Contracts\WorkflowLogRepositoryInterface;
use App\Modules\Workflows\Domain\Entities\WorkflowExecutionLog;
use App\Support\Database\GuardsTenant;
use App\Support\Database\SqlValue;
use Illuminate\Support\Facades\DB;

final class SqlWorkflowLogRepository implements WorkflowLogRepositoryInterface
{
    use GuardsTenant;

    public function __construct(private readonly UserContextDTO $context) {}

    public function save(WorkflowExecutionLog $log): void
    {
        $this->guardTenant($log->partnerId);
        DB::table('workflow_execution_logs')->insertOrIgnore([
            'id' => $log->id,
            'execution_id' => $log->executionId,
            'partner_id' => $log->partnerId,
            'node_id' => $log->nodeId,
            'action_type' => $log->actionType,
            'status' => $log->status,
            'output_message' => $log->outputMessage,
            'created_at' => SqlValue::timestamp($log->timestamp),
        ]);
    }
}
