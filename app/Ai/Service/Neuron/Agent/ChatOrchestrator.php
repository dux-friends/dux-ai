<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Agent;

use App\Ai\Models\AiAgent;
use App\Ai\Models\AiAgentSession;
use App\Ai\Service\Agent\CardParser;
use App\Ai\Service\Agent\Logger as AgentLogger;
use App\Ai\Service\Agent\MessageStore as AgentMessageStore;
use App\Ai\Service\Agent\Sse as AgentSse;
use App\Ai\Service\AgentApproval\ApprovalPersistenceStore;
use App\Ai\Service\AgentApproval\Service as ApprovalService;
use App\Ai\Service\Neuron\History\DbChatHistoryAdapter;
use App\Ai\Service\Neuron\MessageAdapter;
use App\Ai\Service\Usage\UsageResolver;
use App\Ai\Support\AiRuntime;
use Generator;
use GuzzleHttp\Exception\RequestException;
use NeuronAI\Agent\Agent as NeuronAgent;
use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Stream\Chunks\AudioChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ImageChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Observability\LogObserver;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Tools\ToolInterface;
use Throwable;

final class ChatOrchestrator
{
    /**
     * @param array<int, array<string, mixed>> $openaiMessages
     * @param array<string, array<string, mixed>> $toolMap
     * @param array<int, \NeuronAI\Tools\ToolInterface> $tools
     * @param null|callable():\NeuronAI\Chat\History\ChatHistoryInterface $chatHistoryFactory
     * @param array<string, mixed> $initialToolResult
     * @return Generator<int, string>
     */
    public static function run(
        AIProviderInterface $provider,
        AiAgent $agent,
        string $agentCode,
        int $sessionId,
        string $modelForDisplay,
        string $instructions,
        array $openaiMessages,
        array $toolMap,
        array $tools,
        int $promptTokens,
        ?ChatHistoryInterface $chatHistory = null,
        ?callable $chatHistoryFactory = null,
        int $userMessageId = 0,
        array $attachmentSettings = [],
        array $initialToolResult = [],
        string $workflowId = '',
        ?ApprovalRequest $resumeRequest = null,
        array $approvalContext = [],
    ): Generator {
        if ($userMessageId > 0) {
            AgentMessageStore::markUserMessageRunning($userMessageId);
        }
        $assistantRecord = AgentMessageStore::appendMessage($agent->id, $sessionId, 'assistant', '');
        $assistantMessageId = (int)$assistantRecord->id;
        $replyBuffer = '';
        $lastToolCallAssistantMessageId = $assistantMessageId;
        $debugEnabled = (bool)($agent->settings['debug_enabled'] ?? false);
        $pendingToolCalls = [];
        $pendingToolResultParts = is_array($initialToolResult['parts'] ?? null) ? ($initialToolResult['parts'] ?? []) : [];
        $pendingToolResultSummary = trim((string)($initialToolResult['summary'] ?? ''));
        $finalAssistantParts = [];
        $assistantUsage = null;
        $maxRetries = 2;
        $attempt = 0;
        $modelOptions = is_array($agent->model?->options ?? null) ? ($agent->model?->options ?? []) : [];
        $budget = TokenEstimator::estimateChatBudget(
            $instructions,
            $openaiMessages,
            $toolMap,
            is_array($agent->settings ?? null) ? ($agent->settings ?? []) : [],
            $modelOptions,
        );
        $rateReservation = ModelRateLimiter::acquireForAgent($agent, (int)$budget['total']);
        if (($rateReservation['enabled'] ?? false) && ((int)($rateReservation['waited_ms'] ?? 0) > 0 || (bool)($rateReservation['forced'] ?? false))) {
            AiRuntime::instance()->log('ai.agent')->info('agent.chat.rate_limit', [
                'agent' => $agentCode,
                'session_id' => $sessionId,
                'model_key' => (string)($rateReservation['model_key'] ?? ''),
                'limit' => (int)($rateReservation['limit'] ?? 0),
                'requested_tokens' => (int)($rateReservation['requested_tokens'] ?? 0),
                'used_tokens' => (int)($rateReservation['used_tokens'] ?? 0),
                'waited_ms' => (int)($rateReservation['waited_ms'] ?? 0),
                'forced' => (bool)($rateReservation['forced'] ?? false),
            ]);
        }
        if ((int)($rateReservation['waited_ms'] ?? 0) > 0) {
            yield AgentSse::statusChunk($sessionId, 'queued', '模型限速排队中', [
                'waited_ms' => (int)($rateReservation['waited_ms'] ?? 0),
                'requested_tokens' => (int)($rateReservation['requested_tokens'] ?? 0),
                'limit' => (int)($rateReservation['limit'] ?? 0),
            ]);
        }
        yield AgentSse::statusChunk($sessionId, 'thinking', '正在调用模型', [
            'requested_tokens' => (int)($rateReservation['requested_tokens'] ?? 0),
        ]);

        while (true) {
            try {
                $neuron = NeuronAgent::make()
                    ->setAiProvider($provider)
                    ->setInstructions($instructions);
                if ($workflowId !== '') {
                    $neuron->setPersistence(ApprovalPersistenceStore::make(), $workflowId);
                }
                if ($debugEnabled) {
                    $neuron->observe(new LogObserver(AiRuntime::instance()->log('ai.neuron.agent')));
                }
                $history = $chatHistoryFactory ? $chatHistoryFactory() : $chatHistory;
                if ($history) {
                    $neuron->setChatHistory($history);
                }

                $summaryMaxTokens = isset($agent->settings['summary_max_tokens']) && is_numeric($agent->settings['summary_max_tokens'])
                    ? (int)$agent->settings['summary_max_tokens']
                    : 50000;
                $summaryKeep = isset($agent->settings['summary_messages_keep']) && is_numeric($agent->settings['summary_messages_keep'])
                    ? (int)$agent->settings['summary_messages_keep']
                    : 5;
                if ($summaryMaxTokens > 0 && $summaryKeep > 0) {
                    $neuron->addGlobalMiddleware(new Summarization(
                        provider: $provider,
                        maxTokens: $summaryMaxTokens,
                        messagesToKeep: $summaryKeep,
                    ));
                }

                if ($tools !== []) {
                    $neuron->addTool($tools);
                    $neuron->addGlobalMiddleware(new DangerousToolApproval($toolMap));
                }

                $supportImage = array_key_exists('support_image', $attachmentSettings)
                    ? (bool)$attachmentSettings['support_image']
                    : true;
                $supportFile = array_key_exists('support_file', $attachmentSettings)
                    ? (bool)$attachmentSettings['support_file']
                    : true;

                $imageMode = (string)($attachmentSettings['support_image_model'] ?? 'auto');
                if (!in_array($imageMode, ['auto', 'url', 'base64'], true)) {
                    $imageMode = 'auto';
                }
                $fileMode = (string)($attachmentSettings['support_file_model'] ?? 'auto');
                if (!in_array($fileMode, ['auto', 'base64'], true)) {
                    $fileMode = 'auto';
                }

                if ($imageMode === 'auto') {
                    $imageMode = $provider instanceof Ollama ? 'base64' : 'url';
                }

                $neuronMessages = MessageAdapter::fromOpenAIMessages(
                    $openaiMessages,
                    $supportImage,
                    $supportFile,
                    [
                        'image_mode' => $imageMode,
                        'document_mode' => $fileMode,
                    ]
                );

                $handler = $neuron->stream($neuronMessages, $resumeRequest);
                $events = $handler->events();
                $events->rewind();
                while ($events->valid()) {
                    $chunk = $events->current();
                    if ($chunk instanceof ToolCallChunk) {
                        yield AgentSse::statusChunk($sessionId, 'tool_call', '正在调用工具', [
                            'tool' => (string)$chunk->tool->getName(),
                            'tool_call_id' => (string)($chunk->tool->getCallId() ?? ''),
                        ]);
                        $pendingToolCalls[] = self::toolCallPayload($chunk->tool);
                        $events->next();
                        continue;
                    }

                    if ($chunk instanceof ToolResultChunk) {
                        yield AgentSse::statusChunk($sessionId, 'tool_result', '工具结果已返回，继续推理中', [
                            'tool' => (string)$chunk->tool->getName(),
                            'tool_call_id' => (string)($chunk->tool->getCallId() ?? ''),
                        ]);
                        if ($assistantMessageId > 0) {
                            AgentMessageStore::persistAssistantMessage(
                                $assistantMessageId,
                                $replyBuffer,
                                $pendingToolCalls !== [] ? ['tool_calls' => $pendingToolCalls] : []
                            );
                            $lastToolCallAssistantMessageId = $assistantMessageId;
                            $assistantMessageId = 0;
                            $replyBuffer = '';
                        }

                        $toolState = self::persistToolResultAndCollect($agent, $sessionId, $toolMap, $chunk->tool, $lastToolCallAssistantMessageId);
                        $parts = is_array($toolState['parts'] ?? null) ? ($toolState['parts'] ?? []) : [];
                        if ($parts !== []) {
                            $pendingToolResultParts = $parts;
                            $pendingToolResultSummary = trim((string)($toolState['summary'] ?? ''));
                        }
                        $pendingToolCalls = [];
                        yield AgentSse::format([
                            'session_id' => $sessionId,
                            'tool_result' => (string)($toolState['public_result'] ?? ''),
                            'tool_result_parts' => is_array($toolState['parts'] ?? null) ? ($toolState['parts'] ?? []) : [],
                            'tool_result_summary' => (string)($toolState['summary'] ?? ''),
                            'tool' => (string)($toolState['tool'] ?? ''),
                            'tool_label' => (string)($toolState['tool_label'] ?? ''),
                            'tool_call_id' => $toolState['tool_call_id'] ?? null,
                            'message_id' => $toolState['message_id'] ?? null,
                        ]);
                        $events->next();
                        continue;
                    }

                    $text = self::extractStreamText($chunk);
                    if ($text === '') {
                        $events->next();
                        continue;
                    }

                    if ($assistantMessageId <= 0) {
                        $assistantRecord = AgentMessageStore::appendMessage($agent->id, $sessionId, 'assistant', '');
                        $assistantMessageId = (int)$assistantRecord->id;
                    }

                    $replyBuffer .= $text;
                    yield AgentSse::openAIChunk($text, $sessionId, $assistantMessageId, $modelForDisplay);
                    $events->next();
                }

                $workflowState = $events->getReturn();
                $assistantUsage = null;
                if (is_object($workflowState) && method_exists($workflowState, 'getMessage')) {
                    $finalMessage = $workflowState->getMessage();
                    if (is_object($finalMessage) && method_exists($finalMessage, 'getUsage')) {
                        $usage = $finalMessage->getUsage();
                        if (is_object($usage) && method_exists($usage, 'jsonSerialize')) {
                            $serialized = $usage->jsonSerialize();
                            $assistantUsage = is_array($serialized) ? $serialized : null;
                        }
                    }
                }
                break;
            } catch (WorkflowInterrupt $interrupt) {
                /** @var AiAgentSession|null $session */
                $session = AiAgentSession::query()->find($sessionId);
                if (!$session) {
                    throw $interrupt;
                }
                $approval = ApprovalService::createFromInterrupt(
                    $interrupt,
                    $agent,
                    $session,
                    $userMessageId,
                    isset($approvalContext['source_type']) ? (string)$approvalContext['source_type'] : null,
                    isset($approvalContext['source_id']) ? (int)$approvalContext['source_id'] : null,
                );
                if ($assistantMessageId > 0) {
                    AgentMessageStore::persistAssistantMessage($assistantMessageId, '', []);
                    $assistantMessageId = 0;
                }
                ModelRateLimiter::finalize($rateReservation, (int)($budget['total'] ?? 0));
                yield AgentSse::statusChunk($sessionId, 'approval_required', '危险动作等待审批', [
                    'approval_id' => (int)$approval->id,
                    'risk_level' => (string)$approval->risk_level,
                    'tool' => (string)($approval->tool_name ?? ''),
                    'action' => (string)($approval->action_name ?? ''),
                ]);
                yield AgentSse::done();
                return;
            } catch (Throwable $e) {
                if (self::shouldRetryRateLimit($e) && $attempt < $maxRetries && $replyBuffer === '') {
                    $attempt++;
                    $pendingToolCalls = [];
                    $delayMs = self::retryDelayMs($attempt);
                    yield AgentSse::statusChunk($sessionId, 'retry_wait', '模型限速，自动重试中', [
                        'attempt' => $attempt,
                        'delay_ms' => $delayMs,
                    ]);
                    AiRuntime::instance()->log('ai.agent')->warning('agent.chat.retry', [
                        'agent' => $agentCode,
                        'session_id' => $sessionId,
                        'attempt' => $attempt,
                        'delay_ms' => $delayMs,
                        'reason' => $e->getMessage(),
                        'history_reload' => $chatHistoryFactory !== null || $chatHistory instanceof DbChatHistoryAdapter,
                    ]);
                    usleep($delayMs * 1000);
                    yield AgentSse::statusChunk($sessionId, 'thinking', '正在重新调用模型', [
                        'attempt' => $attempt,
                    ]);
                    continue;
                }

                if ($e instanceof RequestException) {
                    try {
                        $reqBody = (string)$e->getRequest()?->getBody();
                    } catch (Throwable) {
                        $reqBody = '';
                    }
                    $respBody = '';
                    if ($e->hasResponse()) {
                        try {
                            $respBody = (string)$e->getResponse()?->getBody();
                        } catch (Throwable) {
                            $respBody = '';
                        }
                    }
                    if (mb_strlen($reqBody, 'UTF-8') > 10000) {
                        $reqBody = mb_substr($reqBody, 0, 10000, 'UTF-8') . '…(truncated)';
                    }
                    if (mb_strlen($respBody, 'UTF-8') > 10000) {
                        $respBody = mb_substr($respBody, 0, 10000, 'UTF-8') . '…(truncated)';
                    }
                    AiRuntime::instance()->log('ai.agent')->error('agent.provider.request_error', [
                        'agent' => $agentCode,
                        'session_id' => $sessionId,
                        'message' => $e->getMessage(),
                        'request' => [
                            'method' => $e->getRequest()?->getMethod(),
                            'uri' => (string)($e->getRequest()?->getUri() ?? ''),
                            'body' => $reqBody,
                        ],
                        'response_body' => $respBody,
                    ]);
                }
                $fallbackText = trim($replyBuffer);
                if ($fallbackText === '') {
                    $fallbackText = $pendingToolResultSummary;
                }

                if ($fallbackText !== '') {
                    if ($assistantMessageId <= 0) {
                        $assistantRecord = AgentMessageStore::appendMessage($agent->id, $sessionId, 'assistant', '');
                        $assistantMessageId = (int)$assistantRecord->id;
                    }

                    $parts = $pendingToolResultParts;
                    if ($parts !== [] && !self::looksLikeStructuredPayload($fallbackText)) {
                        array_unshift($parts, [
                            'type' => 'text',
                            'text' => $fallbackText,
                        ]);
                        AgentMessageStore::persistAssistantMessage(
                            $assistantMessageId,
                            $fallbackText,
                            ['parts' => $parts]
                        );
                    } else {
                        AgentMessageStore::persistAssistantMessage(
                            $assistantMessageId,
                            $fallbackText,
                            []
                        );
                    }

                    yield AgentSse::openAIChunk($fallbackText, $sessionId, $assistantMessageId, $modelForDisplay);
                    $mediaParts = self::extractNonTextParts($parts);
                    if ($mediaParts !== []) {
                        yield AgentSse::openAIChunk([
                            'content' => $mediaParts,
                        ], $sessionId, $assistantMessageId, $modelForDisplay);
                    }

                    yield AgentSse::format([
                        'session_id' => $sessionId,
                        'choices' => [
                            [
                                'index' => 0,
                                'finish_reason' => 'stop',
                                'delta' => new \stdClass(),
                            ],
                        ],
                        'id' => $assistantMessageId ? sprintf('msg_%d', $assistantMessageId) : null,
                        'model' => $modelForDisplay,
                        'object' => 'chat.completion.chunk',
                        'created' => time(),
                    ]);
                    if ($userMessageId > 0) {
                        AgentMessageStore::markUserMessageCompleted($userMessageId);
                    }
                    ModelRateLimiter::finalize($rateReservation, (int)($budget['input_tokens'] + UsageResolver::fromUsageOrEstimate($assistantUsage ?? null, $fallbackText)['completion_tokens']));
                    yield AgentSse::done();
                    return;
                }

                if ($assistantMessageId) {
                    $errorText = $replyBuffer !== '' ? $replyBuffer : $e->getMessage();
                    if (mb_strlen($errorText, 'UTF-8') > 2000) {
                        $errorText = mb_substr($errorText, 0, 2000, 'UTF-8') . '...';
                    }
                    AgentMessageStore::persistAssistantMessage(
                        $assistantMessageId,
                        $errorText,
                        ['error' => true]
                    );
                }
                if ($userMessageId > 0) {
                    AgentMessageStore::markUserMessageFailed($userMessageId, $e->getMessage());
                }
                ModelRateLimiter::finalize($rateReservation, (int)($budget['total'] ?? 0));
                AgentLogger::debug($debugEnabled, 'agent.chat.error', [
                    'agent' => $agentCode,
                    'session_id' => $sessionId,
                    'message' => $e->getMessage(),
                ]);
                yield AgentSse::errorChunk($sessionId, $modelForDisplay, $assistantMessageId ?: null, $e->getMessage());
                yield AgentSse::done();
                return;
            }
        }

        if ($assistantMessageId) {
            if ($pendingToolResultParts !== []) {
                $replyText = trim($replyBuffer);
                $parts = $pendingToolResultParts;
                if (!self::looksLikeStructuredPayload($replyText) && $replyText !== '') {
                    array_unshift($parts, [
                        'type' => 'text',
                        'text' => $replyText,
                    ]);
                }
                $summary = $replyText !== '' && !self::looksLikeStructuredPayload($replyText)
                    ? $replyText
                    : ($pendingToolResultSummary !== '' ? $pendingToolResultSummary : '已返回结果');

                AgentMessageStore::persistAssistantMessage(
                    $assistantMessageId,
                    $summary,
                    ['parts' => $parts]
                );
                $finalAssistantParts = $parts;
            } else {
                $structured = CardParser::extractStructuredResult($replyBuffer);
                if ($structured) {
                    $summary = trim((string)($structured['summary'] ?? ''));
                    if ($summary === '') {
                        $summary = '已返回结果';
                    }
                    AgentMessageStore::persistAssistantMessage(
                        $assistantMessageId,
                        $summary,
                        ['parts' => is_array($structured['parts'] ?? null) ? ($structured['parts'] ?? []) : []]
                    );
                    $finalAssistantParts = is_array($structured['parts'] ?? null) ? ($structured['parts'] ?? []) : [];
                } else {
                    AgentMessageStore::persistAssistantMessage(
                        $assistantMessageId,
                        $replyBuffer,
                        []
                    );
                }
            }
        }

        $resolvedUsage = UsageResolver::fromUsageOrEstimate($assistantUsage ?? null, $replyBuffer);
        AgentMessageStore::recordUsage(
            $agent,
            $sessionId,
            $resolvedUsage['prompt_tokens'] > 0 ? $resolvedUsage['prompt_tokens'] : $promptTokens,
            $resolvedUsage['completion_tokens']
        );
        AiRuntime::instance()->log('ai.agent')->info('agent.usage.recorded', [
            'agent' => $agentCode,
            'session_id' => $sessionId,
            'usage_source' => $resolvedUsage['usage_source'],
            'usage_missing' => $resolvedUsage['usage_missing'],
            'prompt_tokens' => $resolvedUsage['prompt_tokens'],
            'completion_tokens' => $resolvedUsage['completion_tokens'],
            'total_tokens' => $resolvedUsage['total_tokens'],
        ]);
        ModelRateLimiter::finalize(
            $rateReservation,
            max(0, (int)($resolvedUsage['total_tokens'] ?? 0))
        );
        if ($userMessageId > 0) {
            AgentMessageStore::markUserMessageCompleted($userMessageId);
        }

        $mediaParts = self::extractNonTextParts($finalAssistantParts);
        if ($assistantMessageId > 0 && $mediaParts !== []) {
            yield AgentSse::openAIChunk([
                'content' => $mediaParts,
            ], $sessionId, $assistantMessageId, $modelForDisplay);
        }

        yield AgentSse::format([
            'session_id' => $sessionId,
            'choices' => [
                [
                    'index' => 0,
                    'finish_reason' => 'stop',
                    'delta' => new \stdClass(),
                ],
            ],
            'id' => $assistantMessageId ? sprintf('msg_%d', $assistantMessageId) : null,
            'model' => $modelForDisplay,
            'object' => 'chat.completion.chunk',
            'created' => time(),
        ]);
        yield AgentSse::done();
    }

    private static function extractStreamText(mixed $chunk): string
    {
        if (is_string($chunk)) {
            return $chunk;
        }

        if (
            $chunk instanceof TextChunk
            || $chunk instanceof ReasoningChunk
            || $chunk instanceof ImageChunk
            || $chunk instanceof AudioChunk
        ) {
            return (string)$chunk->content;
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function toolCallPayload(ToolInterface $tool): array
    {
        return [
            'id' => (string)($tool->getCallId() ?? ''),
            'type' => 'function',
            'function' => [
                'name' => $tool->getName(),
                'arguments' => json_encode($tool->getInputs(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $toolMap
     * @return array{
     *     parts: array<int, array<string, mixed>>,
     *     summary: string,
     *     public_result: string,
     *     tool: string,
     *     tool_label: string,
     *     tool_call_id: string|null,
     *     message_id: string|null
     * }
     */
    private static function persistToolResultAndCollect(AiAgent $agent, int $sessionId, array $toolMap, ToolInterface $tool, int $assistantMessageId): array
    {
        $toolName = (string)$tool->getName();
        $toolCallId = (string)($tool->getCallId() ?? '');
        $toolLabel = (string)($toolMap[$toolName]['label'] ?? $toolName);

        $rawText = (string)$tool->getResult();
        $decoded = null;
        if ($rawText !== '' && json_validate($rawText)) {
            $tmp = json_decode($rawText, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decoded = $tmp;
            }
        }
        $toolResult = $decoded ?? $rawText;
        $normalized = self::normalizeToolResult($toolResult, $rawText);
        $summary = $normalized['summary'];
        $toolFailed = $normalized['failed'];

        $payload = [
            'tool_label' => $toolLabel,
        ];
        if (is_array($toolResult)) {
            $payload['raw'] = $toolResult;
        } elseif ($rawText !== '') {
            $payload['raw_text'] = $rawText;
        }
        if ($summary !== '') {
            $payload['tool_summary'] = $summary;
        }
        $toolResultParts = self::extractToolResultParts($toolResult);
        if ($toolFailed) {
            $payload['error'] = [
                'message' => (string)($normalized['debug_message'] ?? $summary),
                'type' => (string)($normalized['error_type'] ?? 'tool_error'),
                'retryable' => (bool)($normalized['retryable'] ?? false),
                'user_message' => $summary,
            ];
        }

        $toolContent = $summary !== ''
            ? $summary
            : (is_string($toolResult) && trim($toolResult) !== '' ? trim($toolResult) : $toolLabel);

        AgentMessageStore::appendMessage(
            $agent->id,
            $sessionId,
            'tool',
            $toolContent,
            $payload,
            $toolName,
            $toolCallId !== '' ? $toolCallId : null
        );

        $publicResult = $toolContent;

        return [
            'parts' => $toolResultParts,
            'summary' => $summary,
            'public_result' => $publicResult,
            'tool' => $toolName,
            'tool_label' => $toolLabel,
            'tool_call_id' => $toolCallId !== '' ? $toolCallId : null,
            'message_id' => $assistantMessageId > 0 ? sprintf('msg_%d', $assistantMessageId) : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function extractToolResultParts(mixed $toolResult): array
    {
        $payload = null;
        if (is_array($toolResult)) {
            $encoded = json_encode($toolResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded) && $encoded !== '') {
                $payload = $encoded;
            }
        } elseif (is_string($toolResult)) {
            $payload = $toolResult;
        }

        if (!is_string($payload) || trim($payload) === '') {
            return [];
        }

        $structured = CardParser::extractStructuredResult($payload);
        if (!is_array($structured)) {
            return [];
        }

        $parts = is_array($structured['parts'] ?? null) ? ($structured['parts'] ?? []) : [];
        return self::extractNonTextParts($parts);
    }

    private static function looksLikeStructuredPayload(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return false;
        }
        if ((!str_starts_with($trimmed, '{') && !str_starts_with($trimmed, '[')) || !json_validate($trimmed)) {
            return false;
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            return false;
        }

        if (array_is_list($decoded)) {
            return false;
        }

        if (isset($decoded['type']) || isset($decoded['card']) || isset($decoded['images']) || isset($decoded['videos'])) {
            return true;
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $parts
     * @return array<int, array<string, mixed>>
     */
    private static function extractNonTextParts(array $parts): array
    {
        return array_values(array_filter($parts, static function (mixed $part): bool {
            if (!is_array($part)) {
                return false;
            }
            return (string)($part['type'] ?? '') !== 'text';
        }));
    }

    /**
     * @return array{
     *     summary: string,
     *     failed: bool,
     *     retryable: bool,
     *     error_type: string,
     *     debug_message: string
     * }
     */
    private static function normalizeToolResult(mixed $toolResult, string $rawText): array
    {
        $summary = '';
        $failed = false;
        $retryable = false;
        $errorType = 'tool_error';
        $debugMessage = '';

        if (is_array($toolResult)) {
            $isStructuredError = ($toolResult['__tool_error'] ?? false) === true
                || (isset($toolResult['status']) && (int)$toolResult['status'] === 0 && isset($toolResult['user_message']));

            if ($isStructuredError) {
                $failed = true;
                $summary = trim((string)($toolResult['user_message'] ?? $toolResult['message'] ?? ''));
                $retryable = (bool)($toolResult['retryable'] ?? false);
                $errorType = trim((string)($toolResult['error_type'] ?? 'tool_error'));
                $debugMessage = trim((string)($toolResult['debug_message'] ?? $rawText));
            } else {
                $summary = trim((string)($toolResult['summary'] ?? $toolResult['message'] ?? ''));
            }
        } elseif (is_string($toolResult)) {
            $summary = trim($toolResult);
        }

        if ($failed && $summary === '') {
            $summary = '工具调用失败，系统可能暂时异常，请稍后重试。';
        }
        if ($failed && $debugMessage === '') {
            $debugMessage = $rawText !== '' ? $rawText : $summary;
        }

        return [
            'summary' => $summary,
            'failed' => $failed,
            'retryable' => $retryable,
            'error_type' => $errorType,
            'debug_message' => $debugMessage,
        ];
    }

    private static function shouldRetryRateLimit(Throwable $e): bool
    {
        $message = $e->getMessage();
        $lower = strtolower($message);

        if ($e instanceof RequestException && $e->hasResponse()) {
            $status = $e->getResponse()?->getStatusCode();
            if ($status === 429) {
                return true;
            }
        }

        return str_contains($message, 'TooManyRequests')
            || str_contains($message, 'RequestBurstTooFast')
            || str_contains($lower, 'http 429')
            || str_contains($lower, '429')
            || str_contains($lower, 'rate limit')
            || str_contains($lower, 'serveroverloaded');
    }

    private static function retryDelayMs(int $attempt): int
    {
        return match ($attempt) {
            1 => 600,
            2 => 1400,
            default => 2200,
        };
    }
}
