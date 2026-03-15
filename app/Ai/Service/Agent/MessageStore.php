<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

use App\Ai\Event\AgentMessagePersistedEvent;
use App\Ai\Models\AiAgent;
use App\Ai\Models\AiAgentMessage;
use App\Ai\Models\AiAgentSession;
use App\Ai\Support\AiRuntime;
use Core\App;

final class MessageStore
{
    private static function normalizePayload(array $payload = []): array
    {
        return $payload ?: [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function withRequestState(array $payload, string $status, ?string $error = null, ?bool $retryable = null): array
    {
        $payload = self::normalizePayload($payload);
        $request = is_array($payload['_request'] ?? null) ? ($payload['_request'] ?? []) : [];
        $request['status'] = $status;
        if ($error !== null) {
            $request['error'] = $error;
        } else {
            unset($request['error']);
        }
        if ($retryable !== null) {
            $request['retryable'] = $retryable;
        }
        $payload['_request'] = $request;
        return $payload;
    }

    private static function normalizeContentForStorage(mixed $content): string
    {
        if ($content === null) {
            return '';
        }
        if (is_string($content)) {
            return $content;
        }
        if (is_scalar($content)) {
            return (string)$content;
        }
        if (is_array($content)) {
            return HistoryBuilder::stringifyMessageContent($content);
        }
        return (string)$content;
    }

    private static function buildSessionTitle(string $content): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($content));
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $text = $line;
                break;
            }
        }
        if ($text === '') {
            return '';
        }

        // 标题保留首行，超长截断，避免 UI 展示过长。
        return mb_strimwidth($text, 0, 60, '...');
    }

    private static function fillSessionTitleIfEmpty(int $sessionId, string $role, string $content): void
    {
        if ($role !== 'user') {
            return;
        }

        $title = self::buildSessionTitle($content);
        if ($title === '') {
            return;
        }

        AiAgentSession::query()
            ->where('id', $sessionId)
            ->where(function ($query) {
                $query->whereNull('title')->orWhere('title', '');
            })
            ->update(['title' => $title]);
    }

    public static function appendMessage(int $agentId, int $sessionId, string $role, mixed $content = null, array $payload = [], ?string $tool = null, ?string $toolCallId = null): AiAgentMessage
    {
        $content = self::normalizeContentForStorage($content);
        if ($role === 'user') {
            $payload = self::withRequestState($payload, 'pending');
        }
        $message = AiAgentMessage::query()->create([
            'agent_id' => $agentId,
            'session_id' => $sessionId,
            'role' => $role,
            'content' => $content,
            'payload' => $payload ?: null,
            'tool' => $tool,
            'tool_call_id' => $toolCallId,
        ]);
        AiAgentSession::query()->where('id', $sessionId)->update(['last_message_at' => now()]);
        self::fillSessionTitleIfEmpty($sessionId, $role, $content);
        self::dispatchPersistedEventIfNeeded($message);
        return $message;
    }

    public static function markUserMessageRunning(int $messageId): void
    {
        self::updateUserMessageState($messageId, 'running');
    }

    public static function markUserMessageApprovalRequired(int $messageId, int $approvalId, string $summary = ''): void
    {
        $message = AiAgentMessage::query()->find($messageId);
        if (!$message || $message->role !== 'user') {
            return;
        }

        $payload = is_array($message->payload ?? null) ? ($message->payload ?? []) : [];
        $payload = self::withRequestState($payload, 'approval_required');
        $payload['_approval'] = [
            'id' => $approvalId,
            'status' => 'pending',
            'summary' => $summary,
        ];
        $message->payload = $payload;
        $message->save();
    }

    public static function markUserMessageCompleted(int $messageId): void
    {
        self::updateUserMessageState($messageId, 'completed');
    }

    public static function markUserMessageFailed(int $messageId, string $error, bool $retryable = true): void
    {
        self::updateUserMessageState($messageId, 'error', $error, $retryable);
    }

    private static function updateUserMessageState(int $messageId, string $status, ?string $error = null, ?bool $retryable = null): void
    {
        $message = AiAgentMessage::query()->find($messageId);
        if (!$message || $message->role !== 'user') {
            return;
        }

        $payload = is_array($message->payload ?? null) ? ($message->payload ?? []) : [];
        $message->payload = self::withRequestState($payload, $status, $error, $retryable);
        $message->save();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function persistAssistantMessage(int $messageId, mixed $content, array $payload): void
    {
        $contentText = self::normalizeContentForStorage($content);
        $isEmptyContent = trim($contentText) === '';
        $isEmptyPayload = $payload === [] || $payload === null;

        AiAgentMessage::query()
            ->where('id', $messageId)
            ->when($isEmptyContent && $isEmptyPayload, function ($query) {
                $query->delete();
            }, function ($query) use ($contentText, $payload) {
                $query->update([
                    'content' => $contentText,
                    'payload' => $payload ?: null,
                ]);
            });

        if (!$isEmptyContent || !$isEmptyPayload) {
            /** @var AiAgentMessage|null $message */
            $message = AiAgentMessage::query()->find($messageId);
            if ($message) {
                self::dispatchPersistedEventIfNeeded($message);
            }
        }
    }

    public static function recordUsage(AiAgent $agent, int $sessionId, int $promptTokens, int $completionTokens): void
    {
        $promptTokens = max(0, $promptTokens);
        $completionTokens = max(0, $completionTokens);
        $totalTokens = $promptTokens + $completionTokens;
        if ($promptTokens === 0 && $completionTokens === 0) {
            return;
        }

        $db = AiRuntime::instance()->db()->getConnection();
        $updates = [];
        if ($promptTokens > 0) {
            $updates['prompt_tokens'] = $db->raw(sprintf('GREATEST(0, COALESCE(prompt_tokens,0) + %d)', $promptTokens));
        }
        if ($completionTokens > 0) {
            $updates['completion_tokens'] = $db->raw(sprintf('GREATEST(0, COALESCE(completion_tokens,0) + %d)', $completionTokens));
        }
        $updates['total_tokens'] = $db->raw(sprintf('GREATEST(0, COALESCE(total_tokens,0) + %d)', $totalTokens));
        AiAgentSession::query()->where('id', $sessionId)->update($updates);

        $modelId = $agent->model_id ?: null;
        if ($modelId) {
            \App\Ai\Models\AiModel::recordUsage($modelId, $promptTokens, $completionTokens, $totalTokens);
        }
    }

    private static function dispatchPersistedEventIfNeeded(AiAgentMessage $message): void
    {
        if ((string)$message->role !== 'assistant') {
            return;
        }
        $content = trim((string)($message->content ?? ''));
        $payload = is_array($message->payload ?? null) ? ($message->payload ?? []) : [];
        if ($content === '' && $payload === []) {
            return;
        }
        App::event()->dispatch(new AgentMessagePersistedEvent($message), 'ai.agent.message.persisted');
    }
}
