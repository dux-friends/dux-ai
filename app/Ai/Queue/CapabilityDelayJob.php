<?php

declare(strict_types=1);

namespace App\Ai\Queue;

use App\Ai\Service\Capability\AsyncExecutor;

final class CapabilityDelayJob
{
    /**
     * @param array<string, mixed> $input
     */
    public function __invoke(string $capabilityCode, array $input, string $sourceType = 'agent', int $sourceId = 0): void
    {
        (new AsyncExecutor())->execute($capabilityCode, $input, $sourceType, $sourceId);
    }
}
