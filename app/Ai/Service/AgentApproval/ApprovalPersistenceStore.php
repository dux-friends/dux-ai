<?php

declare(strict_types=1);

namespace App\Ai\Service\AgentApproval;

use App\Ai\Models\AiAgentApproval;
use Core\App;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use Core\Handlers\ExceptionBusiness;

final class ApprovalPersistenceStore implements PersistenceInterface
{
    private const CACHE_PREFIX = 'ai.agent.approval.interrupt.';
    private const CACHE_TTL = 86400;

    public static function make(): self
    {
        return new self();
    }

    public function save(string $workflowId, WorkflowInterrupt $interrupt): void
    {
        AiAgentApproval::query()->updateOrCreate([
            'workflow_id' => $workflowId,
        ], [
            'status' => 'pending',
            'risk_level' => 'dangerous',
        ]);
        self::cache()->set(self::cacheKey($workflowId), serialize($interrupt), self::CACHE_TTL);
    }

    public function load(string $workflowId): WorkflowInterrupt
    {
        $serialized = self::cache()->get(self::cacheKey($workflowId), '');
        if (!is_string($serialized) || trim($serialized) === '') {
            throw new ExceptionBusiness('审批工作流中断状态不存在或已过期');
        }
        $interrupt = @unserialize($serialized);
        if (!$interrupt instanceof WorkflowInterrupt) {
            throw new ExceptionBusiness('审批工作流中断状态已损坏');
        }
        return $interrupt;
    }

    public function delete(string $workflowId): void
    {
        self::cache()->delete(self::cacheKey($workflowId));
    }

    private static function cacheKey(string $workflowId): string
    {
        return self::CACHE_PREFIX . $workflowId;
    }

    private static function cache()
    {
        return App::cache();
    }
}
