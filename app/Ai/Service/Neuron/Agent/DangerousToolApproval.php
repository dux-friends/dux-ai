<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Agent;

use App\Ai\Support\AiRuntime;
use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ActionDecision;

final class DangerousToolApproval extends ToolApproval
{
    /**
     * @param array<string, array<string, mixed>> $toolMap
     */
    public function __construct(private readonly array $toolMap = [])
    {
        parent::__construct([]);
    }

    protected function toolRequiresApproval(ToolInterface $tool): bool
    {
        $meta = $this->resolveToolMeta($tool);
        return (($meta['risk_level'] ?? '') === 'dangerous');
    }

    /**
     * @param ToolInterface[] $tools
     * @return ToolInterface[]
     */
    protected function filterToolsRequiringApproval(array $tools): array
    {
        return array_values(array_filter($tools, fn (ToolInterface $tool): bool => $this->toolRequiresApproval($tool)));
    }

    protected function createAction(ToolInterface $tool): Action
    {
        $meta = $this->resolveToolMeta($tool);
        $displayName = (string)($meta['display_name'] ?? $tool->getName());
        $description = json_encode([
            'tool_name' => $tool->getName(),
            'action' => $meta['action_name'] ?? null,
            'risk_level' => $meta['risk_level'] ?? 'safe',
            'display_name' => $displayName,
            'inputs' => $tool->getInputs(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '(invalid arguments)';

        return new Action(
            id: $tool->getCallId() ?? uniqid('tool_', true),
            name: $displayName,
            description: $description,
            decision: ActionDecision::Pending,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveToolMeta(ToolInterface $tool): array
    {
        $toolName = $tool->getName();
        $inputs = $tool->getInputs();
        $meta = $this->toolMap[$toolName] ?? [];
        $actions = (array)$meta['actions'];

        if ($actions) {
            $action = trim((string)($inputs['action'] ?? ''));
            $actionMeta = [];
            foreach ($actions as $item) {
                if ($item['action'] != $action) {
                    continue;
                }
                $actionMeta = $item;
                break;
            }
            $displayName = $meta['label'] ?: $toolName;
            if ($action) {
                $displayName = sprintf('%s（%s）', $actionMeta['label'] ?: $action, $action);
            }
            return [
                'risk_level' => $actionMeta['risk_level'] ?: $meta['risk_level'] ?: 'safe',
                'display_name' => $displayName,
                'action_name' => $action !== '' ? $action : null,
            ];
        }

        return [
            'risk_level' => $meta['risk_level'] ?: 'safe',
            'display_name' => $meta['label'] ?: $toolName,
            'action_name' => null,
        ];
    }
}
