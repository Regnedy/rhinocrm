<?php

namespace App\Modules\Workflows\Services;

use App\Modules\CRM\Domain\Entities\Contact;
use App\Modules\Workflows\Contracts\WorkflowExecutionRepositoryInterface;
use App\Modules\Workflows\Contracts\WorkflowLogRepositoryInterface;
use App\Modules\Workflows\Contracts\WorkflowRepositoryInterface;
use App\Modules\Workflows\Domain\Entities\WorkflowExecution;
use App\Modules\Workflows\Domain\Entities\WorkflowExecutionLog;
use App\Modules\Workflows\Jobs\ProcessWorkflowNodeJob;
use Illuminate\Support\Str;

final class WorkflowEngineService
{
    public function __construct(
        private readonly WorkflowRepositoryInterface $workflows,
        private readonly WorkflowExecutionRepositoryInterface $executions,
        private readonly WorkflowLogRepositoryInterface $logs,
        private readonly ActionFactory $factory,
        private readonly WorkflowTriggerMatcher $triggerMatcher,
    ) {}

    public function triggerWorkflows(
        string $partnerId,
        string $trigger,
        Contact $contact,
        string $eventId,
        array $eventContext = [],
    ): void {
        $context = array_replace_recursive([
            'event' => [],
            'contact' => [
                'id' => $contact->getId(),
                'lifecycle_stage' => $contact->getLifecycleStage(),
                'sales_stage' => $contact->getSalesStage(),
                'assigned_advisor_id' => $contact->getAssignedAdvisorId(),
                'total_measurements' => $contact->getTotalMeasurements(),
                'metrics' => $contact->getMetricsSummary(),
                'source' => $contact->getContactSource(),
            ],
        ], $eventContext);

        foreach ($this->workflows->getActiveByTrigger($partnerId, $trigger) as $workflow) {
            if (!$this->triggerMatcher->matches($workflow->getTriggerConditions(), $context)) {
                continue;
            }

            $executionId = 'WEX_'.strtoupper(substr(
                hash('sha256', $workflow->getId().'|'.$workflow->getVersion().'|'.$eventId),
                0,
                22,
            ));
            if ($this->executions->find($executionId) !== null) {
                continue;
            }

            $execution = new WorkflowExecution(
                id: $executionId,
                workflowId: $workflow->getId(),
                workflowVersion: $workflow->getVersion(),
                partnerId: $partnerId,
                contactId: $contact->getId(),
                triggerEventId: $eventId,
                currentNodeId: $workflow->getEntryNodeId(),
                status: 'RUNNING',
                nodesSnapshot: $workflow->getNodes(),
            );
            $this->executions->save($execution);
            $this->processExecution($execution, $contact);
        }
    }

    public function processExecution(WorkflowExecution $execution, Contact $contact): void
    {
        if (in_array($execution->getStatus(), ['CANCELED', 'COMPLETED', 'FAILED'], true)) {
            return;
        }
        if ($execution->getStatus() === 'PAUSED') {
            $execution->resume();
        }

        $guard = 0;
        while ($execution->getStatus() === 'RUNNING' && $guard++ < 100) {
            $nodeId = $execution->getCurrentNodeId();
            $node = $execution->getCurrentNodeDef();
            if ($node === null) {
                $execution->complete();
                $this->executions->save($execution);
                $this->log($execution, $nodeId, 'END', 'SUCCESS', 'Flujo completado.');
                return;
            }

            if ($execution->isNodeCompleted($nodeId)) {
                $execution->advance($node['next'] ?? null);
                $this->executions->save($execution);
                continue;
            }

            try {
                $action = $this->factory->make((string) $node['type']);
                $config = array_merge($node['config'] ?? [], ['next' => $node['next'] ?? null]);
                $response = $action->execute($config, $execution, $contact);
                $execution->markNodeAsCompleted($nodeId);
                $this->log($execution, $nodeId, (string) $node['type'], 'SUCCESS', $response->logMessage);

                if ($response->status === 'PAUSE' && $response->resumeAt !== null) {
                    $nextNodeId = $response->nextNodeId ?? '';
                    $execution->pauseAt($nextNodeId, $response->resumeAt);
                    $this->executions->save($execution);
                    ProcessWorkflowNodeJob::dispatch(
                        $execution->getId(),
                        $contact->getId(),
                        $execution->getPartnerId(),
                    )->delay($response->resumeAt);
                    return;
                }

                $execution->advance($response->nextNodeId);
                $this->executions->save($execution);
            } catch (\Throwable $exception) {
                $this->log(
                    $execution,
                    $nodeId,
                    (string) ($node['type'] ?? 'UNKNOWN'),
                    'FAILED',
                    mb_substr($exception->getMessage(), 0, 500),
                );
                throw $exception;
            }
        }

        if ($guard >= 100) {
            $execution->fail('Límite de nodos excedido; posible ciclo en el DAG.');
            $this->executions->save($execution);
            throw new \RuntimeException('Workflow excedió 100 nodos.');
        }
    }

    private function log(
        WorkflowExecution $execution,
        string $nodeId,
        string $type,
        string $status,
        ?string $message,
    ): void {
        $this->logs->save(new WorkflowExecutionLog(
            id: 'WFL_'.strtoupper(Str::random(12)),
            executionId: $execution->getId(),
            partnerId: $execution->getPartnerId(),
            nodeId: $nodeId,
            actionType: $type,
            status: $status,
            outputMessage: $message,
        ));
    }
}
