<?php

declare(strict_types=1);

namespace App\Boot\Service\Driver;

use App\Boot\Models\BootBot;
use App\Boot\Service\DTO\InboundMessage;
use App\Boot\Service\Message;
use Core\App;
use Core\Handlers\ExceptionBusiness;
use Psr\Http\Message\ServerRequestInterface;

class QqBotDriver extends AbstractDriver
{
    private const TOKEN_URL = 'https://bots.qq.com/app/getAppAccessToken';

    // 驱动平台标识
    public function platform(): string
    {
        return 'qq_bot';
    }

    // 平台元信息
    public function meta(): array
    {
        return [
            'label' => 'QQ机器人',
            'value' => 'qq_bot',
            'icon' => 'i-tabler:message-chatbot',
            'color' => 'violet',
            'style' => [
                'iconClass' => 'text-violet-500',
                'iconBgClass' => 'bg-violet-500/10',
            ],
        ];
    }

    // 发送 QQ 机器人消息（OpenAPI）
    public function send(BootBot $bot, Message $message): array
    {
        $cfg = $this->config($bot);
        $appId = trim((string)($cfg['app_id'] ?? ''));
        $appSecret = trim((string)($cfg['app_secret'] ?? ''));
        if ($appId === '' || $appSecret === '') {
            throw new ExceptionBusiness('QQ机器人应用配置不完整，请填写 app_id / app_secret');
        }

        $meta = $message->metaValue();
        $groupOpenId = trim((string)($meta['group_openid'] ?? ''));
        $userOpenId = trim((string)($meta['user_openid'] ?? ''));
        $msgId = trim((string)($meta['msg_id'] ?? $message->replyToEventIdValue() ?? ''));
        $conversationId = trim((string)($message->conversationIdValue() ?? ''));
        if ($groupOpenId === '' && $userOpenId === '') {
            if ($conversationId !== '' && str_starts_with(strtoupper($conversationId), 'A')) {
                $groupOpenId = $conversationId;
            } else {
                $userOpenId = $conversationId;
            }
        }
        if ($groupOpenId === '' && $userOpenId === '') {
            throw new ExceptionBusiness('QQ机器人会话标识为空，请先接收消息后再回复');
        }
        if ($msgId === '') {
            throw new ExceptionBusiness('QQ机器人回复缺少 msg_id，请基于入站消息事件回复');
        }

        $accessToken = $this->accessToken($bot, $appId, $appSecret);
        $sendApi = $groupOpenId !== ''
            ? 'https://api.sgroup.qq.com/v2/groups/' . rawurlencode($groupOpenId) . '/messages'
            : 'https://api.sgroup.qq.com/v2/users/' . rawurlencode($userOpenId) . '/messages';

        $payload = $message->payloadFor($this->platform());
        if (!isset($payload['msg_type']) || (int)$payload['msg_type'] === 0) {
            $payload['msg_type'] = 0;
        }
        if (!isset($payload['content'])) {
            $payload['content'] = $message->textContent();
        }
        $payload['msg_id'] = $msgId;
        $payload['msg_seq'] = (int)($meta['msg_seq'] ?? 1);
        App::log('boot')->info('boot.qq.send', [
            'bot_code' => $bot->code,
            'target' => $groupOpenId !== '' ? 'group' : 'user',
            'has_msg_id' => isset($payload['msg_id']) && trim((string)$payload['msg_id']) !== '',
        ]);

        $result = $this->requestJson(
            $sendApi,
            $payload,
            [
                'Authorization' => 'QQBot ' . $accessToken,
                'X-Union-Appid' => $appId,
            ]
        );

        if ((int)($result['code'] ?? 0) !== 0) {
            throw new ExceptionBusiness('QQ机器人发送失败: ' . (string)($result['message'] ?? 'unknown'));
        }
        return $result;
    }

    // 校验 QQ 机器人签名与实例密钥
    public function verifyInbound(BootBot $bot, ServerRequestInterface $request, array $payload): bool
    {
        if (!$this->ensureInstanceSecret($bot, $request)) {
            return false;
        }
        $cfg = $this->config($bot);
        $secret = trim((string)($cfg['app_secret'] ?? ''));
        if ($secret === '') {
            return true;
        }
        $signature = trim((string)$request->getHeaderLine('X-Signature-Ed25519'));
        $timestamp = trim((string)$request->getHeaderLine('X-Signature-Timestamp'));
        if ($signature === '' || $timestamp === '' || !function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }
        $raw = $this->rawBody($request);
        $message = $timestamp . $raw;
        $signatureBytes = @hex2bin($signature);
        if ($signatureBytes === false) {
            return false;
        }
        $verifyHex = $this->signHex($secret, $message);
        return hash_equals($verifyHex, $signature);
    }

    // 处理回调地址验证事件（op=13）
    public function verifyCallbackPayload(array $payload, BootBot $bot): ?array
    {
        if ((int)($payload['op'] ?? 0) !== 13) {
            return null;
        }
        $plainToken = trim((string)($payload['d']['plain_token'] ?? ''));
        $eventTs = trim((string)($payload['d']['event_ts'] ?? ''));
        if ($plainToken === '' || $eventTs === '') {
            throw new ExceptionBusiness('QQ机器人回调校验参数不完整', 400);
        }
        $cfg = $this->config($bot);
        $secret = trim((string)($cfg['app_secret'] ?? ''));
        if ($secret === '') {
            throw new ExceptionBusiness('QQ机器人 app_secret 未配置', 400);
        }
        return [
            'plain_token' => $plainToken,
            'signature' => $this->signHex($secret, $eventTs . $plainToken),
        ];
    }

    public function resolveWebhookPayloadResponse(
        BootBot $bot,
        array $payload,
        ServerRequestInterface $request
    ): array|string|null {
        return $this->verifyCallbackPayload($payload, $bot);
    }

    // 解析 QQ 机器人回调消息
    public function parseInbound(BootBot $bot, array $payload, ServerRequestInterface $request): InboundMessage
    {
        $data = isset($payload['d']) && is_array($payload['d']) ? $payload['d'] : $payload;
        $rawTimestamp = trim((string)($data['timestamp'] ?? ''));
        $timestamp = $rawTimestamp !== '' ? strtotime($rawTimestamp) : time();
        return new InboundMessage(
            platform: $this->platform(),
            eventId: (string)($data['id'] ?? $payload['id'] ?? md5((string)time())),
            conversationId: (string)($data['group_openid'] ?? $data['author']['user_openid'] ?? $data['author']['member_openid'] ?? $data['channel_id'] ?? ''),
            senderId: (string)($data['author']['member_openid'] ?? $data['author']['user_openid'] ?? $data['author']['id'] ?? ''),
            senderName: (string)($data['author']['username'] ?? ''),
            text: trim((string)($data['content'] ?? '')),
            timestamp: $timestamp ?: time(),
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
            ->replyToEventId($message->eventId)
            ->meta($this->qqReplyMeta($message));

        $this->send($bot, $reply);
        return ['ok' => true];
    }

    private function qqReplyMeta(InboundMessage $message): array
    {
        if (!is_array($message->raw)) {
            return [];
        }
        $data = isset($message->raw['d']) && is_array($message->raw['d']) ? $message->raw['d'] : $message->raw;
        $meta = [];
        $groupOpenId = trim((string)($data['group_openid'] ?? ''));
        if ($groupOpenId !== '') {
            $meta['group_openid'] = $groupOpenId;
        }
        $userOpenId = trim((string)($data['author']['user_openid'] ?? $data['author']['member_openid'] ?? ''));
        if ($userOpenId !== '') {
            $meta['user_openid'] = $userOpenId;
        }
        $msgId = trim((string)($data['id'] ?? $message->eventId));
        if ($msgId !== '') {
            $meta['msg_id'] = $msgId;
        }
        return $meta;
    }

    private function signHex(string $secret, string $message): string
    {
        if (!function_exists('sodium_crypto_sign_detached')) {
            throw new ExceptionBusiness('QQ机器人签名需要 sodium 扩展');
        }
        $seed = $this->normalizeSecret($secret);
        $pair = sodium_crypto_sign_seed_keypair($seed);
        $privateKey = sodium_crypto_sign_secretkey($pair);
        $signature = sodium_crypto_sign_detached($message, $privateKey);
        return strtolower(bin2hex($signature));
    }

    private function normalizeSecret(string $secret): string
    {
        $seed = $secret;
        while (strlen($seed) < 32) {
            $seed .= $seed;
        }
        $seed = substr($seed, 0, 32);
        return $seed;
    }

    private function accessToken(BootBot $bot, string $appId, string $appSecret): string
    {
        $cacheKey = 'boot.bot.qq.token.' . $bot->id;
        $cached = (string)App::cache()->get($cacheKey, '');
        if ($cached !== '') {
            return $cached;
        }

        $result = $this->requestJson(self::TOKEN_URL, [
            'appId' => $appId,
            'clientSecret' => $appSecret,
        ]);
        $token = trim((string)($result['access_token'] ?? ''));
        if ($token === '') {
            throw new ExceptionBusiness('QQ机器人 access_token 获取失败');
        }

        $ttl = max(60, (int)($result['expires_in'] ?? 7200) - 120);
        App::cache()->set($cacheKey, $token, $ttl);
        return $token;
    }
}
