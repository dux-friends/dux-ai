<?php

declare(strict_types=1);

namespace App\Ai\Service;

use App\Ai\Models\AiModel;
use App\Ai\Service\Agent\Sse;
use App\Ai\Service\Neuron\Agent\ModelRateLimiter;
use App\Ai\Service\Neuron\Agent\TokenEstimator;
use App\Ai\Service\Usage\UsageResolver;
use App\Ai\Support\AiRuntime;
use Core\Handlers\ExceptionBusiness;
use NeuronAI\Chat\Messages\UserMessage;

final class EditorAiService
{
    public function generate(string $prompt): \Generator
    {
        $model = $this->resolveModel();
        $reservation = $this->acquireRateReservation($model, $prompt);
        $provider = AI::forModel($model, [], $this->timeout());
        $provider->systemPrompt($this->systemPrompt());

        try {
            $result = $provider->chat(UserMessage::make($prompt));
            $content = trim((string)$result->getContent());
            if ($content === '') {
                throw new ExceptionBusiness('AI 响应为空');
            }

            $usage = UsageResolver::fromUsageOrEstimate(
                $result->getUsage()?->jsonSerialize() ?: [],
                $content
            );
            ModelRateLimiter::finalize($reservation, (int)$usage['total_tokens']);
        } catch (\Throwable $e) {
            ModelRateLimiter::finalize($reservation, (int)($reservation['requested_tokens'] ?? 0));
            throw $e;
        }

        AiModel::recordUsage(
            (int)$model->id,
            (int)$usage['prompt_tokens'],
            (int)$usage['completion_tokens'],
            (int)$usage['total_tokens']
        );

        yield Sse::comment(str_repeat(' ', 2048));
        yield Sse::format([
            'message' => $content,
            'number' => 0,
            'status' => 'success',
        ]);
        yield Sse::done();
    }

    /**
     * @return array{
     *     enabled: bool,
     *     model_key: string,
     *     reservation_id: string,
     *     limit: int,
     *     requested_tokens: int,
     *     used_tokens: int,
     *     waited_ms: int,
     *     forced: bool
     * }
     */
    private function acquireRateReservation(AiModel $model, string $prompt): array
    {
        $modelOptions = is_array($model->options ?? null) ? ($model->options ?? []) : [];
        $budget = TokenEstimator::estimateTextBudget($prompt, $this->systemPrompt(), $modelOptions, [
            'scene' => 'editor',
        ]);
        $reservation = ModelRateLimiter::acquireForModel($model, (int)$budget['total']);

        if ((int)($reservation['waited_ms'] ?? 0) > 0) {
            AiRuntime::instance()->log('ai.model')->info('ai.model.rate_limit', [
                'scene' => 'editor',
                'model_id' => (int)$model->id,
                'model_code' => (string)($model->code ?? ''),
                'requested_tokens' => (int)($reservation['requested_tokens'] ?? 0),
                'limit' => (int)($reservation['limit'] ?? 0),
                'waited_ms' => (int)($reservation['waited_ms'] ?? 0),
            ]);
        }

        return $reservation;
    }

    private function resolveModel(): AiModel
    {
        $defaultModelId = (int)AiConfig::getValue('default_chat_model_id', 0);
        if ($defaultModelId > 0) {
            $model = $this->findActiveChatModel($defaultModelId);
            if ($model) {
                return $model;
            }
        }

        $model = $this->queryActiveChatModels()
            ->orderByDesc('id')
            ->first();

        if (!$model) {
            throw new ExceptionBusiness('当前未配置可用聊天模型');
        }

        return $model;
    }

    private function systemPrompt(): string
    {
        return (string)AiConfig::getValue(
            'editor.system_prompt',
            '你是 AIEditor 的写作助手。直接返回正文内容，不要解释，不要添加前后缀，不要输出 ``` 代码块。'
        );
    }

    private function timeout(): int
    {
        $timeout = (int)AiConfig::getValue('editor.timeout', 60);
        return max(10, min(600, $timeout));
    }

    private function findActiveChatModel(int $id): ?AiModel
    {
        return $this->queryActiveChatModels()
            ->where('id', $id)
            ->first();
    }

    private function queryActiveChatModels()
    {
        return AiModel::query()
            ->with('provider')
            ->where('type', AiModel::TYPE_CHAT)
            ->where('active', true)
            ->whereHas('provider', static function ($query) {
                $query->where('active', true);
            });
    }
}
