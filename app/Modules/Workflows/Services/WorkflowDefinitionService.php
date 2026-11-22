<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use App\Modules\Workflows\Contracts\WorkflowRepositoryInterface;
use App\Modules\Workflows\Domain\Entities\Workflow;
use Illuminate\Support\Str;

final class WorkflowDefinitionService
{
    private const NODE_TYPES = ['DELAY', 'CREATE_TASK', 'SEND_WHATSAPP', 'CONDITION'];

    public function __construct(
        private readonly WorkflowRepositoryInterface $workflows,
        private readonly UserContextDTO $context,
    ) {}

    public function create(array $data): Workflow
    {
        $partnerId = $this->context->partnerId ?? throw new \RuntimeException('Contexto sin partner.', 403);
        $nodes = $this->validateDag((array) $data['nodes'], (string) $data['entry_node_id']);

        $workflow = new Workflow(
            id: 'WFD_'.strtoupper(Str::random(12)),
            partnerId: $partnerId,
            name: trim((string) $data['name']),
            version: 1,
            triggerEvent: (string) $data['trigger_event'],
            triggerConditions: (array) ($data['trigger_conditions'] ?? []),
            nodes: $nodes,
            entryNodeId: (string) $data['entry_node_id'],
            cancelOnStageChange: (bool) ($data['cancel_on_stage_change'] ?? false),
            isActive: (bool) ($data['is_active'] ?? false),
        );

        $this->workflows->save($workflow);

        return $workflow;
    }

    private function validateDag(array $nodes, string $entryNodeId): array
    {
        if (!array_key_exists($entryNodeId, $nodes)) {
            throw new \InvalidArgumentException('El entry_node_id no existe en nodes.');
        }

        foreach ($nodes as $nodeId => $node) {
            if (!is_string($nodeId) || !is_array($node)) {
                throw new \InvalidArgumentException('Definición de nodo inválida.');
            }

            $type = strtoupper((string) ($node['type'] ?? ''));
            if (!in_array($type, self::NODE_TYPES, true)) {
                throw new \InvalidArgumentException("Tipo de nodo no soportado: {$type}.");
            }

            $nodes[$nodeId]['type'] = $type;
            $nodes[$nodeId]['config'] = (array) ($node['config'] ?? []);

            foreach ($this->edgesForNode($nodes[$nodeId]) as $target) {
                if (!array_key_exists($target, $nodes)) {
                    throw new \InvalidArgumentException("El nodo {$nodeId} referencia el nodo inexistente {$target}.");
                }
            }
        }

        $this->assertAcyclic($nodes, $entryNodeId);

        return $nodes;
    }

    /** @return string[] */
    private function edgesForNode(array $node): array
    {
        $edges = [];
        $next = $node['next'] ?? null;
        if (is_string($next) && $next !== '') {
            $edges[] = $next;
        }

        if (($node['type'] ?? null) === 'CONDITION') {
            foreach (['on_true', 'on_false'] as $branch) {
                $target = $node['config'][$branch] ?? null;
                if (is_string($target) && $target !== '') {
                    $edges[] = $target;
                }
            }
        }

        return array_values(array_unique($edges));
    }

    private function assertAcyclic(array $nodes, string $entry): void
    {
        $visiting = [];
        $visited = [];

        $visit = function (string $nodeId) use (&$visit, &$visiting, &$visited, $nodes): void {
            if (isset($visited[$nodeId])) {
                return;
            }
            if (isset($visiting[$nodeId])) {
                throw new \InvalidArgumentException('El workflow contiene un ciclo; se requiere un DAG.');
            }

            $visiting[$nodeId] = true;
            foreach ($this->edgesForNode($nodes[$nodeId]) as $target) {
                $visit($target);
            }
            unset($visiting[$nodeId]);
            $visited[$nodeId] = true;
        };

        $visit($entry);
    }
}
