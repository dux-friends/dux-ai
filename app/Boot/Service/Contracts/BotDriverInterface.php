<?php

declare(strict_types=1);

namespace App\Boot\Service\Contracts;

use App\Boot\Models\BootBot;
use App\Boot\Service\DTO\InboundMessage;
use App\Boot\Service\Message;
use Psr\Http\Message\ServerRequestInterface;

interface BotDriverInterface
{
    // 返回平台唯一标识
    public function platform(): string;

    // 返回平台配置元数据（用于后台选项）
    public function meta(): array;

    // 发送消息到对应平台
    public function send(BootBot $bot, Message $message): array;

    // 处理回调前置验证（如 URL 验证），返回非 null 则直接作为响应
    public function verifyCallbackRequest(BootBot $bot, ServerRequestInterface $request): array|string|null;

    // 解析平台原始 webhook 负载
    public function parseWebhookPayload(BootBot $bot, ServerRequestInterface $request): array;

    // 校验平台回调请求合法性
    public function verifyInbound(BootBot $bot, ServerRequestInterface $request, array $payload): bool;

    // 处理 payload 级验证事件（如 challenge），返回非 null 则直接作为响应
    public function resolveWebhookPayloadResponse(
        BootBot $bot,
        array $payload,
        ServerRequestInterface $request
    ): array|string|null;

    // 将平台回调解析为统一入站消息
    public function parseInbound(BootBot $bot, array $payload, ServerRequestInterface $request): InboundMessage;

    // 处理回调回复（由驱动决定 ACK 或被动/主动回复实现）
    public function handleWebhookReply(
        BootBot $bot,
        ServerRequestInterface $request,
        InboundMessage $message,
        ?string $replyText,
        bool $ackOnly
    ): array|string;
}
