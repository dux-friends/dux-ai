<?php

declare(strict_types=1);

namespace App\Boot\Service;

use App\Boot\Event\BotMessageReceivedEvent;
use App\Boot\Event\BotMessageReplyEvent;
use App\Boot\Models\BootBot;
use App\Boot\Models\BootMessageLog;
use App\Boot\Service\DTO\InboundMessage;
use Core\App;
use Core\Handlers\ExceptionBusiness;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class BotService
{
    private const FALLBACK_REPLY_TEXT = '系统已收到您的消息';

    private ?BotFactory $factory = null;

    // 获取平台选项
    public function platformOptions(): array
    {
        return $this->factory()->options();
    }

    // 通过机器人编码发送文本消息
    public function sendByCode(string $code, string $text, array $meta = []): array
    {
        return $this->sendMessageByCode($code, Message::text($text)->meta($meta));
    }

    // 通过机器人编码发送结构化消息
    public function sendMessageByCode(string $code, Message $message): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new ExceptionBusiness('机器人编码不能为空');
        }
        $bot = BootBot::query()->where('code', $code)->first();
        if (!$bot || !$bot->enabled) {
            throw new ExceptionBusiness('机器人实例不存在或未启用');
        }
        return $this->send($bot, $message);
    }

    // 使用指定机器人实例发送消息并记录日志
    public function send(BootBot $bot, Message $message): array
    {
        $driver = $this->factory()->driver($bot->platform);
        try {
            $result = $driver->send($bot, $message);
            $this->logOutbound($bot, $message, $result, 'ok');
            return $result;
        } catch (Throwable $e) {
            $this->logOutbound($bot, $message, [], 'fail', $e->getMessage());
            throw $e;
        }
    }

    // 处理统一 webhook 回调并派发接收事件
    public function handleWebhook(string $code, ServerRequestInterface $request): array|string
    {
        $bot = BootBot::query()->where('code', $code)->first();
        if (!$bot || !$bot->enabled) {
            throw new ExceptionBusiness('机器人实例不存在或未启用', 404);
        }

        $driver = $this->factory()->driver($bot->platform);
        $verifyResponse = $driver->verifyCallbackRequest($bot, $request);
        if ($verifyResponse !== null) {
            return $verifyResponse;
        }

        $payload = $driver->parseWebhookPayload($bot, $request);

        if (!$driver->verifyInbound($bot, $request, $payload)) {
            $this->logInboundRaw($bot, $payload, 'fail', '签名校验失败');
            throw new ExceptionBusiness('签名校验失败', 401);
        }

        $payloadResponse = $driver->resolveWebhookPayloadResponse($bot, $payload, $request);
        if ($payloadResponse !== null) {
            return $payloadResponse;
        }

        $message = $driver->parseInbound($bot, $payload, $request);
        $this->logInboundMessage($bot, $message, 'ok');

        $event = new BotMessageReceivedEvent($bot, $message);
        App::event()->dispatch($event, 'boot.message.received');

        $ackOnly = $event->isAckOnly();
        $replyText = trim((string)$event->getReplyText());
        if (!$ackOnly && $replyText === '') {
            $replyText = self::FALLBACK_REPLY_TEXT;
        }
        App::log('boot')->info('boot.webhook.reply.decided', [
            'code' => (string)$bot->code,
            'platform' => (string)$bot->platform,
            'event_id' => (string)$message->eventId,
            'conversation_id' => (string)$message->conversationId,
            'reply_mode' => $ackOnly ? 'ack_only' : 'reply_text',
            'reply_length' => $ackOnly ? 0 : mb_strlen($replyText, 'UTF-8'),
        ]);

        $result = $driver->handleWebhookReply($bot, $request, $message, $replyText, $ackOnly);
        if (!$ackOnly) {
            App::event()->dispatch(new BotMessageReplyEvent($bot->code, $replyText), 'boot.message.reply');
        }
        return $result;
    }

    // 记录未成功解析的入站日志
    private function logInboundRaw(BootBot $bot, array $payload, string $status, ?string $error = null): void
    {
        BootMessageLog::query()->create([
            'bot_id' => $bot->id,
            'direction' => 'inbound',
            'platform' => $bot->platform,
            'message_type' => 'text',
            'raw_payload' => $payload,
            'status' => $status,
            'error' => $error,
        ]);
    }

    // 记录标准化后的入站日志
    private function logInboundMessage(BootBot $bot, InboundMessage $message, string $status): void
    {
        BootMessageLog::query()->create([
            'bot_id' => $bot->id,
            'direction' => 'inbound',
            'platform' => $bot->platform,
            'event_id' => $message->eventId,
            'conversation_id' => $message->conversationId,
            'sender_id' => $message->senderId,
            'sender_name' => $message->senderName,
            'message_type' => 'text',
            'content' => $message->text,
            'raw_payload' => $message->raw,
            'status' => $status,
        ]);
    }

    // 记录出站日志
    private function logOutbound(BootBot $bot, Message $message, array $raw, string $status, ?string $error = null): void
    {
        BootMessageLog::query()->create([
            'bot_id' => $bot->id,
            'direction' => 'outbound',
            'platform' => $bot->platform,
            'event_id' => $message->replyToEventIdValue(),
            'conversation_id' => $message->conversationIdValue(),
            'message_type' => $message->type(),
            'content' => $message->contentForLog(),
            'raw_payload' => $raw,
            'status' => $status,
            'error' => $error,
        ]);
    }

    // 延迟初始化工厂实例
    private function factory(): BotFactory
    {
        if (!$this->factory) {
            $this->factory = new BotFactory();
        }
        return $this->factory;
    }
}
