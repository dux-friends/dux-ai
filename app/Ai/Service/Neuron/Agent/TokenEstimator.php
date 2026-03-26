<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Agent;

use App\Ai\Service\Agent\OpenAiMessage;

final class TokenEstimator
{
    /**
     * @param array<int, array<string, mixed>> $openaiMessages
     * @param array<string, array<string, mixed>> $toolMap
     * @param array<string, mixed> $agentSettings
     * @param array<string, mixed> $modelOptions
     * @return array{
     *     input_tokens: int,
     *     output_tokens: int,
     *     tool_overhead: int,
     *     safety_margin: int,
     *     total: int
     * }
     */
    public static function estimateChatBudget(
        string $instructions,
        array $openaiMessages,
        array $toolMap = [],
        array $agentSettings = [],
        array $modelOptions = [],
    ): array {
        $inputTokens = self::estimateText($instructions);
        foreach ($openaiMessages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $inputTokens += self::estimateContent($message['content'] ?? '');
        }

        $toolOverhead = self::estimateToolOverhead($toolMap);
        $outputTokens = self::resolveOutputReserve($agentSettings, $modelOptions);
        $base = max(1, $inputTokens + $outputTokens + $toolOverhead);
        $safetyMargin = max(200, (int)ceil($base * 0.15));

        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'tool_overhead' => $toolOverhead,
            'safety_margin' => $safetyMargin,
            'total' => $base + $safetyMargin,
        ];
    }

    /**
     * @param array<string, mixed> $modelOptions
     * @param array<string, mixed> $extra
     * @return array{
     *     input_tokens: int,
     *     output_tokens: int,
     *     extra_tokens: int,
     *     safety_margin: int,
     *     total: int
     * }
     */
    public static function estimateTextBudget(
        string $prompt,
        ?string $systemPrompt = null,
        array $modelOptions = [],
        array $extra = [],
    ): array {
        $inputTokens = self::estimateText($prompt) + self::estimateText((string)$systemPrompt);
        $extraText = $extra === [] ? '' : (json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $extraTokens = self::estimateText($extraText);
        $outputTokens = self::resolveOutputReserve([], $modelOptions);
        $base = max(1, $inputTokens + $extraTokens + $outputTokens);
        $safetyMargin = max(200, (int)ceil($base * 0.15));

        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'extra_tokens' => $extraTokens,
            'safety_margin' => $safetyMargin,
            'total' => $base + $safetyMargin,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $toolMap
     */
    private static function estimateToolOverhead(array $toolMap): int
    {
        if ($toolMap === []) {
            return 0;
        }

        $json = json_encode(array_values($toolMap), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $estimated = self::estimateText(is_string($json) ? $json : '');

        return max(300, min(2000, $estimated));
    }

    /**
     * @param array<string, mixed> $agentSettings
     * @param array<string, mixed> $modelOptions
     */
    private static function resolveOutputReserve(array $agentSettings, array $modelOptions): int
    {
        $candidates = [
            $agentSettings['max_output_tokens'] ?? null,
            $agentSettings['max_completion_tokens'] ?? null,
            $modelOptions['max_output_tokens'] ?? null,
            $modelOptions['max_completion_tokens'] ?? null,
            is_array($modelOptions['rate_limit'] ?? null) ? (($modelOptions['rate_limit'] ?? [])['max_output_tokens'] ?? null) : null,
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return max(128, min(4096, (int)$candidate));
            }
        }

        return 600;
    }

    private static function estimateContent(mixed $content): int
    {
        if (is_string($content)) {
            return self::estimateText($content);
        }

        if (is_array($content)) {
            return self::estimateText(OpenAiMessage::stringifyContent($content));
        }

        return self::estimateText((string)$content);
    }

    private static function estimateText(string $text): int
    {
        $normalized = trim($text);
        if ($normalized === '') {
            return 0;
        }

        return (int)max(1, ceil(mb_strlen($normalized, 'UTF-8') * 1.3));
    }
}
