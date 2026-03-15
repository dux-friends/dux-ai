<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

use App\Ai\Models\AiAgent;
use App\Ai\Models\AiAgentMessage;

final class IncomingMessageHandler
{
    /**
     * @return array<string, mixed>
     */
    private static function normalizePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (is_string($payload)) {
            $trimmed = trim($payload);
            if ($trimmed !== '' && (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) && json_validate($payload)) {
                $decoded = json_decode($payload, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        return [];
    }

    /**
     * Extract the first text segment for storage/display.
     */
    private static function extractFirstText(mixed $contentValue, array $payload): string
    {
        $parts = null;
        if (isset($payload['parts'])) {
            $candidate = $payload['parts'];
            if (is_array($candidate) && array_is_list($candidate)) {
                $parts = $candidate;
            } elseif (is_string($candidate) && trim($candidate) !== '' && str_starts_with(ltrim($candidate), '[') && json_validate($candidate)) {
                $decoded = json_decode($candidate, true);
                if (is_array($decoded) && array_is_list($decoded)) {
                    $parts = $decoded;
                }
            }
        }
        if ($parts === null && is_array($contentValue) && array_is_list($contentValue)) {
            $parts = $contentValue;
        }
        if ($parts !== null) {
            foreach ($parts as $part) {
                if (!is_array($part)) {
                    continue;
                }
                if (($part['type'] ?? '') === 'text' && isset($part['text'])) {
                    $text = trim((string)$part['text']);
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }

        // Fallback: stringify but keep only the first non-empty line.
        $text = HistoryBuilder::stringifyMessageContent($contentValue);
        $text = str_replace("\r\n", "\n", (string)$text);
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line !== '') {
                return $line;
            }
        }
        return trim($text);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array{user_text: string, stored_content: mixed, payload: array<string, mixed>, content: mixed}|null
     */
    public static function latestUserInput(array $messages): ?array
    {
        foreach (array_reverse($messages) as $msg) {
            if (($msg['role'] ?? '') !== 'user' || empty($msg['content'])) {
                continue;
            }
            $contentValue = $msg['content'];
            $payload = self::normalizePayload($msg['payload'] ?? null);

            if (!array_key_exists('parts', $payload)) {
                if (is_array($contentValue) && array_is_list($contentValue)) {
                    $payload['parts'] = $contentValue;
                } elseif (is_string($contentValue) && trim($contentValue) !== '' && str_starts_with(ltrim($contentValue), '[') && json_validate($contentValue)) {
                    $decoded = json_decode($contentValue, true);
                    if (is_array($decoded) && array_is_list($decoded)) {
                        $payload['parts'] = $decoded;
                    }
                }
            }

            return [
                'user_text' => self::extractFirstText($contentValue, $payload),
                'stored_content' => self::extractFirstText($contentValue, $payload),
                'payload' => $payload,
                'content' => $contentValue,
            ];
        }
        return null;
    }

    /**
     * 从请求 messages 中提取最新一条 user 消息并入库。
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array{user_text: string, stored_content: mixed, message_id: int}
     */
    public static function appendLatestUserMessage(AiAgent $agent, int $sessionId, array $messages): array
    {
        $input = self::latestUserInput($messages);
        if (!$input) {
            return [
                'user_text' => '',
                'stored_content' => null,
                'message_id' => 0,
            ];
        }

        $record = MessageStore::appendMessage($agent->id, $sessionId, 'user', $input['stored_content'], $input['payload']);

        return [
            'user_text' => $input['user_text'],
            'stored_content' => $input['stored_content'],
            'message_id' => $record->id,
        ];
    }

    /**
     * @return array{user_text: string, stored_content: mixed, message_id: int, messages: array<int, array<string, mixed>>}
     */
    public static function buildRetryInput(AiAgentMessage $message): array
    {
        $payload = is_array($message->payload ?? null) ? ($message->payload ?? []) : [];
        $parts = is_array($payload['parts'] ?? null) && array_is_list($payload['parts']) ? ($payload['parts'] ?? []) : null;
        $content = $parts ?: (string)($message->content ?? '');

        return [
            'user_text' => self::extractFirstText($content, $payload),
            'stored_content' => $message->content,
            'message_id' => $message->id,
            'use_openai_messages' => true,
            'messages' => [[
                'role' => 'user',
                'content' => $content,
                'payload' => $payload,
            ]],
        ];
    }

    public static function sameAsFailedMessage(AiAgentMessage $message, array $messages): bool
    {
        $input = self::latestUserInput($messages);
        if (!$input) {
            return false;
        }

        $payload = is_array($message->payload ?? null) ? ($message->payload ?? []) : [];
        $payloadParts = is_array($payload['parts'] ?? null) && array_is_list($payload['parts']) ? ($payload['parts'] ?? []) : [];
        $inputParts = is_array($input['payload']['parts'] ?? null) && array_is_list($input['payload']['parts'] ?? null) ? ($input['payload']['parts'] ?? []) : [];

        if ($payloadParts !== [] || $inputParts !== []) {
            return $payloadParts === $inputParts;
        }

        return trim((string)$message->content) === trim((string)$input['stored_content']);
    }
}
