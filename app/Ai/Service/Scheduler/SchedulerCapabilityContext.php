<?php

declare(strict_types=1);

namespace App\Ai\Service\Scheduler;

use App\Ai\Interface\AgentCapabilityContextInterface;
use App\Ai\Interface\CapabilityContextInterface;
use App\Ai\Interface\FlowCapabilityContextInterface;

final class SchedulerCapabilityContext implements CapabilityContextInterface, AgentCapabilityContextInterface, FlowCapabilityContextInterface
{
    public function __construct(
        private readonly string $scope,
        private readonly int $sourceId = 0,
        private readonly int $agentId = 0,
    ) {
    }

    public function scope(): string
    {
        return $this->scope === 'flow' ? 'flow' : 'agent';
    }

    public function sessionId(): int
    {
        return $this->scope() === 'agent' ? max(0, $this->sourceId) : 0;
    }

    public function agentId(): int
    {
        return $this->scope() === 'agent' ? max(0, $this->agentId) : 0;
    }

    public function flowId(): int
    {
        return $this->scope() === 'flow' ? max(0, $this->sourceId) : 0;
    }
}
