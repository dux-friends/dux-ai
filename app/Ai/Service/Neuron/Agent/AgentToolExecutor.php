<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Agent;

use App\Ai\Service\Tool as ToolService;
use Core\Handlers\ExceptionBusiness;
use Throwable;

final class AgentToolExecutor
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private readonly string $toolCode,
        private readonly array $meta,
        private readonly int $sessionId,
        private readonly int $agentId,
    ) {
    }

    public function __invoke(mixed ...$args): mixed
    {
        if ($this->toolCode === '') {
            throw new ExceptionBusiness(sprintf('工具 [%s] 配置缺失 code', (string)($this->meta['label'] ?? 'unknown')));
        }

        try {
            return ToolService::execute($this->toolCode, [
                ...$this->meta,
                '__session_id' => $this->sessionId,
                '__agent_id' => $this->agentId,
            ], $args);
        } catch (Throwable $e) {
            return ToolFactory::encodeToolError($e);
        }
    }
}
