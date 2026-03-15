<?php

declare(strict_types=1);

namespace App\Ai\Service\Capability;

use App\Ai\Models\AiAgentSession;
use App\Ai\Service\Agent\MessageStore;
use App\Ai\Service\Capability;
use App\Ai\Service\Scheduler\SchedulerCapabilityContext;
use Core\Handlers\ExceptionBusiness;

final class AsyncExecutor
{
    /**
     * @param array<string, mixed> $capabilityInput
     * @return array<string, mixed>
     */
    public function execute(string $capabilityCode, array $capabilityInput, string $sourceType = 'agent', ?int $sourceId = null): array
    {
        $callbackScope = in_array($sourceType, ['agent', 'flow'], true) ? $sourceType : 'agent';

        $meta = Capability::get($capabilityCode);
        if (!$meta) {
            throw new ExceptionBusiness(sprintf('Capability [%s] 未注册', $capabilityCode));
        }
        $types = is_array($meta['types'] ?? null) ? ($meta['types'] ?? []) : [];
        if (!in_array($callbackScope, $types, true)) {
            throw new ExceptionBusiness(sprintf('Capability [%s] 不支持 %s 调度', $capabilityCode, $callbackScope));
        }

        $schedulerContext = $this->buildCapabilityContext($callbackScope, (int)$sourceId);
        $result = Capability::execute($capabilityCode, [
            ...$capabilityInput,
            '__from_scheduler' => true,
        ], $schedulerContext);

        if (is_array($result) && array_key_exists('status', $result)) {
            $status = (int)($result['status'] ?? 0);
            if ($status === 0) {
                throw new ExceptionBusiness((string)($result['message'] ?? $result['content'] ?? '调度任务执行失败'));
            }
        }

        $this->writebackBySource($callbackScope, (int)$sourceId, $capabilityCode, $capabilityInput, $result);

        return [
            'callback_type' => 'capability',
            'capability' => $capabilityCode,
            'input' => $capabilityInput,
            'result' => $result,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function writebackBySource(string $sourceType, int $sourceId, string $capabilityCode, array $input, mixed $result): void
    {
        if ($sourceType === 'agent') {
            $this->writebackAgentSession($sourceId, $capabilityCode, $input, $result);
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function writebackAgentSession(int $sourceId, string $capabilityCode, array $input, mixed $result): void
    {
        if ($sourceId <= 0) {
            return;
        }

        /** @var AiAgentSession|null $session */
        $session = AiAgentSession::query()->find($sourceId);
        if (!$session) {
            return;
        }

        $summary = '';
        if (is_array($result)) {
            $summary = trim((string)($result['summary'] ?? $result['message'] ?? ''));
        }
        if ($summary === '') {
            $summary = sprintf('异步任务 %s 执行完成', $capabilityCode);
        }

        MessageStore::appendMessage(
            (int)$session->agent_id,
            (int)$session->id,
            'assistant',
            $summary,
            [
                'async' => [
                    'capability' => $capabilityCode,
                    'input' => $input,
                ],
                'result' => is_array($result) ? $result : ['value' => $result],
            ],
        );
    }

    private function buildCapabilityContext(string $callbackScope, int $sourceId): SchedulerCapabilityContext
    {
        if ($callbackScope !== 'agent') {
            return new SchedulerCapabilityContext($callbackScope, $sourceId);
        }

        if ($sourceId <= 0) {
            throw new ExceptionBusiness('调度任务缺少 Agent 会话上下文');
        }

        /** @var AiAgentSession|null $session */
        $session = AiAgentSession::query()->find($sourceId);
        if (!$session) {
            throw new ExceptionBusiness(sprintf('调度任务会话 [%d] 不存在', $sourceId));
        }

        return new SchedulerCapabilityContext('agent', $sourceId, (int)$session->agent_id);
    }
}
