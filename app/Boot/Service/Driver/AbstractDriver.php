<?php

declare(strict_types=1);

namespace App\Boot\Service\Driver;

use App\Boot\Models\BootBot;
use App\Boot\Service\Contracts\BotDriverInterface;
use App\Boot\Service\DTO\InboundMessage;
use App\Boot\Service\Message;
use GuzzleHttp\Client;
use Psr\Http\Message\ServerRequestInterface;

abstract class AbstractDriver implements BotDriverInterface
{
    public function verifyCallbackRequest(BootBot $bot, ServerRequestInterface $request): array|string|null
    {
        return null;
    }

    public function parseWebhookPayload(BootBot $bot, ServerRequestInterface $request): array
    {
        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $body = (string)$stream;
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $data = json_decode($body, true);
        if (is_array($data)) {
            return $data;
        }
        $parsed = $request->getParsedBody();
        return is_array($parsed) ? $parsed : [];
    }

    public function resolveWebhookPayloadResponse(
        BootBot $bot,
        array $payload,
        ServerRequestInterface $request
    ): array|string|null {
        return null;
    }

    // 读取机器人实例配置
    protected function config(BootBot $bot): array
    {
        return is_array($bot->config) ? $bot->config : [];
    }

    // 读取 webhook 地址
    protected function webhook(BootBot $bot): string
    {
        return trim((string)($this->config($bot)['webhook'] ?? ''));
    }

    // 当前不启用实例级密钥校验
    protected function ensureInstanceSecret(BootBot $bot, ServerRequestInterface $request): bool
    {
        return true;
    }

    // 获取请求体文本并在可回绕时复位
    protected function rawBody(ServerRequestInterface $request): string
    {
        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $raw = (string)$stream;
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        return $raw;
    }

    // 发送 JSON 请求
    protected function requestJson(string $url, array $payload, array $headers = [], array $query = [], string $method = 'POST', int $timeout = 10): array
    {
        $client = new Client(['timeout' => $timeout]);
        $options = [
            'headers' => $headers,
            'json' => $payload,
        ];
        if ($query) {
            $options['query'] = $query;
        }

        $response = $client->request(strtoupper($method), $url, $options);
        $content = (string)$response->getBody();
        $data = json_decode($content, true);
        return is_array($data) ? $data : ['raw' => $content];
    }

    // 从多种平台结构中提取文本内容
    protected function parseText(array $payload): string
    {
        $candidates = [
            (string)($payload['text']['content'] ?? ''),
            (string)($payload['content']['text'] ?? ''),
            (string)($payload['message']['content'] ?? ''),
            (string)($payload['message']['text'] ?? ''),
            (string)($payload['text'] ?? ''),
        ];
        foreach ($candidates as $text) {
            $text = trim($text);
            if ($text !== '') {
                return $text;
            }
        }
        return '';
    }

    // 将平台原始 payload 归一化为入站消息
    protected function buildInbound(string $platform, array $payload, ServerRequestInterface $request): InboundMessage
    {
        $eventId = trim((string)($payload['event_id'] ?? $payload['message_id'] ?? $payload['msg_id'] ?? md5($this->rawBody($request))));
        $conversationId = trim((string)($payload['conversation_id'] ?? $payload['chat_id'] ?? $payload['session_id'] ?? ''));
        $senderId = trim((string)($payload['sender_id'] ?? $payload['user_id'] ?? $payload['from']['id'] ?? ''));
        $senderName = trim((string)($payload['sender_name'] ?? $payload['from']['name'] ?? $payload['nickname'] ?? ''));
        $timestamp = (int)($payload['timestamp'] ?? $payload['time'] ?? time());
        return new InboundMessage(
            platform: $platform,
            eventId: $eventId ?: md5((string)time()),
            conversationId: $conversationId,
            senderId: $senderId,
            senderName: $senderName,
            text: $this->parseText($payload),
            timestamp: $timestamp > 0 ? $timestamp : time(),
            raw: $payload,
        );
    }

    public function handleWebhookReply(
        BootBot $bot,
        ServerRequestInterface $request,
        InboundMessage $message,
        ?string $replyText,
        bool $ackOnly
    ): array|string {
        if ($ackOnly) {
            return ['ok' => true];
        }
        $text = trim((string)$replyText);
        if ($text === '') {
            return ['ok' => true];
        }
        $outbound = Message::text($text)
            ->conversationId($message->conversationId)
            ->replyToEventId($message->eventId);
        $this->send($bot, $outbound);
        return ['ok' => true];
    }
}
