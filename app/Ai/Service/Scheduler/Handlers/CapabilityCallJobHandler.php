<?php

declare(strict_types=1);

namespace App\Ai\Service\Scheduler\Handlers;

use App\Ai\Models\AiScheduler;
use App\Ai\Service\Capability\AsyncExecutor;
use Core\Handlers\ExceptionBusiness;

final class CapabilityCallJobHandler
{
    /**
     * @return array<string, mixed>
     */
    public function handle(AiScheduler $job): array
    {
        $capabilityCode = trim((string)$job->callback_code);
        if ($capabilityCode === '') {
            throw new ExceptionBusiness('capability 回调缺少 callback_code');
        }
        $capabilityInput = is_array($job->callback_params ?? null) ? ($job->callback_params ?? []) : [];
        return (new AsyncExecutor())->execute(
            $capabilityCode,
            $capabilityInput,
            (string)$job->source_type,
            (int)($job->source_id ?? 0),
        );
    }
}
