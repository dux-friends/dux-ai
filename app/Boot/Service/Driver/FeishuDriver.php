<?php

declare(strict_types=1);

namespace App\Boot\Service\Driver;

use App\Boot\Models\BootBot;
use App\Boot\Service\DTO\InboundMessage;
use App\Boot\Service\Message;
use Core\App;
use Core\Handlers\ExceptionBusiness;
use Psr\Http\Message\ServerRequestInterface;

class FeishuDriver extends AbstractDriver
{
    private const TOKEN_URL = 'https://open.feishu.cn/open-apis/auth/v3/tenant_access_token/internal';
    private const SEND_URL = 'https://open.feishu.cn/open-apis/im/v1/messages';
    private const REPLY_URL = 'https://open.feishu.cn/open-apis/im/v1/messages/%s/reply';

    // 驱动平台标识
    public function platform(): string
    {
        return 'feishu';
    }

    // 平台元信息
    public function meta(): array
    {
        return [
            'label' => '飞书',
            'value' => 'feishu',
            'icon' => 'i-tabler:brand-slack',
            'color' => 'teal',
            'style' => [
                'iconClass' => 'text-indigo-500',
                'iconBgClass' => 'bg-indigo-500/10',
            ],
        ];
    }

    // 使用飞书应用接口发送消息
    public function send(BootBot $bot, Message $message): array
    {
        $cfg = $this->config($bot);
        $appId = trim((string)($cfg['app_id'] ?? ''));
        $appSecret = trim((string)($cfg['app_secret'] ?? ''));
        if ($appId === '' || $appSecret === '') {
            throw new ExceptionBusiness('飞书应用配置不完整，请填写 app_id / app_secret');
        }

        $token = $this->tenantAccessToken($bot, $appId, $appSecret);
        $payload = $message->payloadFor($this->platform());
        $msgType = trim((string)($payload['msg_type'] ?? 'text'));
        if ($msgType === '') {
            $msgType = 'text';
        }
        $content = $payload['content'] ?? ($payload['card'] ?? []);
        if (is_string($content)) {
            $content = trim($content);
        } elseif (is_array($content)) {
            $content = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        } else {
            $content = '{}';
        }

        $replyMessageId = trim((string)($message->replyToEventIdValue() ?? ''));
        if ($replyMessageId !== '') {
            $result = $this->requestJson(
                sprintf(self::REPLY_URL, rawurlencode($replyMessageId)),
                [
                    'msg_type' => $msgType,
                    'content' => $content,
                ],
                [
                    'Authorization' => 'Bearer ' . $token,
                ]
            );
        } else {
            $receiveId = trim((string)($message->conversationIdValue() ?? ''));
            if ($receiveId === '') {
                throw new ExceptionBusiness('飞书接收对象为空，请先接收消息后再回复');
            }

            $result = $this->requestJson(
                self::SEND_URL,
                [
                    'receive_id' => $receiveId,
                    'msg_type' => $msgType,
                    'content' => $content,
                ],
                [
                    'Authorization' => 'Bearer ' . $token,
                ],
                [
                    'receive_id_type' => 'chat_id',
                ]
            );
        }

        if ((int)($result['code'] ?? 0) !== 0) {
            throw new ExceptionBusiness('飞书发送失败: ' . (string)($result['msg'] ?? 'unknown'));
        }
        return $result;
    }

    // 校验飞书回调签名与实例密钥
    public function verifyInbound(BootBot $bot, ServerRequestInterface $request, array $payload): bool
    {
        if (!$this->ensureInstanceSecret($bot, $request)) {
            return false;
        }

        $cfg = $this->config($bot);
        $verificationToken = trim((string)($cfg['verification_token'] ?? ''));
        if ($verificationToken !== '') {
            $token = trim((string)($payload['header']['token'] ?? $payload['token'] ?? ''));
            if ($token !== '' && !hash_equals($verificationToken, $token)) {
                return false;
            }
        }

        $encryptKey = trim((string)($cfg['encrypt_key'] ?? ''));
        if ($encryptKey === '') {
            return true;
        }

        $timestamp = trim((string)$request->getHeaderLine('X-Lark-Request-Timestamp'));
        $nonce = trim((string)$request->getHeaderLine('X-Lark-Request-Nonce'));
        $signature = trim((string)$request->getHeaderLine('X-Lark-Signature'));
        if ($timestamp === '' || $nonce === '' || $signature === '') {
            return false;
        }

        $raw = $this->rawBody($request);
        $expected = hash('sha256', $timestamp . $nonce . $encryptKey . $raw);
        return hash_equals($expected, $signature);
    }

    public function resolveWebhookPayloadResponse(
        BootBot $bot,
        array $payload,
        ServerRequestInterface $request
    ): array|string|null {
        if ((string)($payload['type'] ?? '') !== 'url_verification') {
            return null;
        }
        return ['challenge' => (string)($payload['challenge'] ?? '')];
    }

    // 解析飞书回调消息
    public function parseInbound(BootBot $bot, array $payload, ServerRequestInterface $request): InboundMessage
    {
        $cfg = $this->config($bot);
        $payload = $this->decryptPayload($payload, trim((string)($cfg['encrypt_key'] ?? '')));

        $event = isset($payload['event']) && is_array($payload['event']) ? $payload['event'] : $payload;
        $message = isset($event['message']) && is_array($event['message']) ? $event['message'] : [];
        $sender = isset($event['sender']) && is_array($event['sender']) ? $event['sender'] : [];

        $rawContent = (string)($message['content'] ?? '');
        $content = json_decode($rawContent, true);
        $text = is_array($content)
            ? trim((string)($content['text'] ?? ''))
            : trim($rawContent);

        return new InboundMessage(
            platform: $this->platform(),
            eventId: (string)($message['message_id'] ?? $payload['header']['event_id'] ?? md5((string)time())),
            conversationId: (string)($message['chat_id'] ?? ''),
            senderId: (string)($sender['sender_id']['open_id'] ?? $sender['sender_id']['union_id'] ?? ''),
            senderName: (string)($sender['sender_id']['user_id'] ?? ''),
            text: $text,
            timestamp: (int)($payload['header']['create_time'] ?? time()),
            raw: $payload,
        );
    }

    // 获取并缓存 tenant_access_token
    private function tenantAccessToken(BootBot $bot, string $appId, string $appSecret): string
    {
        $cacheKey = 'boot.bot.feishu.token.' . $bot->id;
        $cached = (string)App::cache()->get($cacheKey, '');
        if ($cached !== '') {
            return $cached;
        }

        $result = $this->requestJson(self::TOKEN_URL, [
            'app_id' => $appId,
            'app_secret' => $appSecret,
        ]);

        if ((int)($result['code'] ?? 0) !== 0) {
            throw new ExceptionBusiness('飞书 tenant_access_token 获取失败: ' . (string)($result['msg'] ?? 'unknown'));
        }

        $token = trim((string)($result['tenant_access_token'] ?? ''));
        if ($token === '') {
            throw new ExceptionBusiness('飞书 tenant_access_token 获取失败');
        }

        $ttl = max(60, (int)($result['expire'] ?? 7200) - 120);
        App::cache()->set($cacheKey, $token, $ttl);

        return $token;
    }

    // 解密飞书加密事件体（配置 encrypt_key 时启用）
    private function decryptPayload(array $payload, string $encryptKey): array
    {
        if ($encryptKey === '') {
            return $payload;
        }

        $encrypt = trim((string)($payload['encrypt'] ?? ''));
        if ($encrypt === '') {
            return $payload;
        }

        $cipher = base64_decode($encrypt, true);
        if ($cipher === false) {
            throw new ExceptionBusiness('飞书回调解密失败');
        }

        $key = hash('sha256', $encryptKey, true);
        $iv = substr($key, 0, 16);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if (!is_string($plain) || $plain === '') {
            throw new ExceptionBusiness('飞书回调解密失败');
        }

        $data = json_decode($plain, true);
        if (!is_array($data)) {
            throw new ExceptionBusiness('飞书回调解析失败');
        }

        return $data;
    }
}
