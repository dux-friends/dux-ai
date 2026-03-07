<?php

declare(strict_types=1);

namespace App\Boot\Service\Driver;

use App\Boot\Models\BootBot;
use App\Boot\Service\DTO\InboundMessage;
use App\Boot\Service\Message;
use Core\App;
use Core\Handlers\ExceptionBusiness;
use Psr\Http\Message\ServerRequestInterface;

class DingtalkDriver extends AbstractDriver
{
    public function platform(): string
    {
        return 'dingtalk';
    }

    public function meta(): array
    {
        return [
            'label' => '钉钉',
            'value' => 'dingtalk',
            'icon' => 'i-tabler:brand-telegram',
            'color' => 'blue',
            'style' => [
                'iconClass' => 'text-sky-500',
                'iconBgClass' => 'bg-sky-500/10',
            ],
        ];
    }

    public function send(BootBot $bot, Message $message): array
    {
        $cfg = $this->config($bot);
        $webhook = trim((string)($cfg['webhook'] ?? ''));
        if ($webhook === '') {
            throw new ExceptionBusiness('钉钉消息 webhook 未配置');
        }
        return $this->sendByWebhook($bot, $message, $cfg, $webhook);
    }

    private function sendByWebhook(BootBot $bot, Message $message, array $cfg, string $webhook): array
    {
        [$baseWebhook, $queryParams] = $this->splitWebhook($webhook);
        $signSecret = trim((string)($cfg['sign_secret'] ?? ''));
        if ($signSecret !== '') {
            $timestamp = (string)round(microtime(true) * 1000);
            $queryParams = array_merge($queryParams, [
                'timestamp' => $timestamp,
                'sign' => base64_encode(hash_hmac('sha256', $timestamp . "\n" . $signSecret, $signSecret, true)),
            ]);
        }

        $payload = $message->payloadFor($this->platform());
        App::log('boot')->info('boot.dingtalk.webhook.send', [
            'bot_code' => $bot->code,
            'has_access_token' => isset($queryParams['access_token']) && trim((string)($queryParams['access_token'] ?? '')) !== '',
        ]);

        $result = $this->requestJson($baseWebhook, $payload, [], $queryParams);
        if ((int)($result['errcode'] ?? 0) !== 0) {
            throw new ExceptionBusiness('钉钉发送失败: ' . (string)($result['errmsg'] ?? 'unknown'));
        }
        return $result;
    }

    private function splitWebhook(string $webhook): array
    {
        $parts = parse_url($webhook);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return [$webhook, []];
        }
        $base = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $base .= ':' . $parts['port'];
        }
        $base .= $parts['path'] ?? '';

        $queryParams = [];
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $queryParams);
        }
        return [$base, $queryParams];
    }

    public function verifyInbound(BootBot $bot, ServerRequestInterface $request, array $payload): bool
    {
        return $this->ensureInstanceSecret($bot, $request);
    }

    public function parseInbound(BootBot $bot, array $payload, ServerRequestInterface $request): InboundMessage
    {
        $event = isset($payload['event']) && is_array($payload['event']) ? $payload['event'] : $payload;
        $text = trim((string)($event['text']['content'] ?? $event['text'] ?? ''));

        return new InboundMessage(
            platform: $this->platform(),
            eventId: (string)($event['messageId'] ?? $event['eventId'] ?? md5((string)time())),
            conversationId: (string)($event['conversationId'] ?? $event['openConversationId'] ?? ''),
            senderId: (string)($event['senderStaffId'] ?? $event['senderId'] ?? ''),
            senderName: (string)($event['senderNick'] ?? ''),
            text: $text,
            timestamp: (int)($event['createAt'] ?? time()),
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
        $reply = Message::text($text)
            ->conversationId($message->conversationId)
            ->replyToEventId($message->eventId);
        $payload = $reply->payloadFor($this->platform());
        $sessionWebhook = trim((string)($this->dingtalkSessionWebhook($message) ?? ''));
        if ($sessionWebhook !== '') {
            App::log('boot')->info('boot.dingtalk.reply.session_webhook', [
                'bot_code' => $bot->code,
                'event_id' => $message->eventId,
                'conversation_id' => $message->conversationId,
            ]);
            $this->postToWebhook($sessionWebhook, $payload);
            return ['ok' => true];
        }
        $this->send($bot, $reply);
        return ['ok' => true];
    }

    private function dingtalkSessionWebhook(InboundMessage $message): ?string
    {
        if (!is_array($message->raw)) {
            return null;
        }
        $event = isset($message->raw['event']) && is_array($message->raw['event']) ? $message->raw['event'] : $message->raw;
        $expire = (int)($event['sessionWebhookExpiredTime'] ?? 0);
        if ($expire > 0 && $expire < (int)round(microtime(true) * 1000)) {
            return null;
        }
        $webhook = trim((string)($event['sessionWebhook'] ?? ''));
        return $webhook !== '' ? $webhook : null;
    }

    private function postToWebhook(string $webhook, array $payload): array
    {
        [$baseWebhook, $queryParams] = $this->splitWebhook($webhook);
        $result = $this->requestJson($baseWebhook, $payload, [], $queryParams);
        if ((int)($result['errcode'] ?? 0) !== 0) {
            throw new ExceptionBusiness('钉钉发送失败: ' . (string)($result['errmsg'] ?? 'unknown'));
        }
        return $result;
    }
}
