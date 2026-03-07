<?php

declare(strict_types=1);

namespace App\Boot\Service;

final class Message
{
    private string $type = 'text';
    private string $text = '';
    private string $title = '';
    private string $imageUrl = '';
    private string $videoUrl = '';
    private array $card = [];
    private ?string $conversationId = null;
    private ?string $replyToEventId = null;
    private array $atUserIds = [];
    private array $meta = [];
    private array $platformPayload = [];

    private function __construct()
    {
    }

    // 创建文本消息
    public static function text(string $text): self
    {
        $instance = new self();
        $instance->type = 'text';
        $instance->text = trim($text);
        return $instance;
    }

    // 创建 Markdown 消息
    public static function markdown(string $title, string $text): self
    {
        $instance = new self();
        $instance->type = 'markdown';
        $instance->title = trim($title);
        $instance->text = trim($text);
        return $instance;
    }

    // 创建图片消息（URL）
    public static function image(string $imageUrl, string $alt = ''): self
    {
        $instance = new self();
        $instance->type = 'image';
        $instance->imageUrl = trim($imageUrl);
        $instance->text = trim($alt);
        return $instance;
    }

    // 创建视频消息（URL）
    public static function video(string $videoUrl, string $title = ''): self
    {
        $instance = new self();
        $instance->type = 'video';
        $instance->videoUrl = trim($videoUrl);
        $instance->text = trim($title);
        return $instance;
    }

    // 创建卡片消息（统一卡片结构）
    public static function card(array $card): self
    {
        $instance = new self();
        $instance->type = 'card';
        $instance->card = $card;
        return $instance;
    }

    // 设置会话ID
    public function conversationId(?string $conversationId): self
    {
        $this->conversationId = $conversationId ? trim($conversationId) : null;
        return $this;
    }

    // 设置回复事件ID
    public function replyToEventId(?string $replyToEventId): self
    {
        $this->replyToEventId = $replyToEventId ? trim($replyToEventId) : null;
        return $this;
    }

    // 设置@用户ID（钉钉）
    public function atUserIds(array $userIds): self
    {
        $this->atUserIds = array_values(array_filter(array_map(
            static fn ($item) => trim((string)$item),
            $userIds
        )));
        return $this;
    }

    // 设置扩展上下文
    public function meta(array $meta): self
    {
        $this->meta = $meta;
        return $this;
    }

    // 为指定平台设置原生 payload（优先级最高）
    public function platformPayload(string $platform, array $payload): self
    {
        $platform = strtolower(trim($platform));
        if ($platform !== '') {
            $this->platformPayload[$platform] = $payload;
        }
        return $this;
    }

    // 获取消息类型
    public function type(): string
    {
        return $this->type;
    }

    // 获取文本内容
    public function textContent(): string
    {
        return $this->text;
    }

    // 获取用于日志记录的文本内容
    public function contentForLog(): string
    {
        return match ($this->type) {
            'image' => trim(($this->text !== '' ? $this->text : '图片') . ' ' . $this->imageUrl),
            'video' => trim(($this->text !== '' ? $this->text : '视频') . ' ' . $this->videoUrl),
            'card' => json_encode($this->card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
            default => $this->text,
        };
    }

    // 获取图片URL（仅 image 类型有效）
    public function imageUrlValue(): string
    {
        return $this->imageUrl;
    }

    // 获取视频URL（仅 video 类型有效）
    public function videoUrlValue(): string
    {
        return $this->videoUrl;
    }

    // 获取会话ID
    public function conversationIdValue(): ?string
    {
        return $this->conversationId;
    }

    // 获取回复事件ID
    public function replyToEventIdValue(): ?string
    {
        return $this->replyToEventId;
    }

    // 获取扩展上下文
    public function metaValue(): array
    {
        return $this->meta;
    }

    // 按平台生成发送 payload
    public function payloadFor(string $platform): array
    {
        $platform = strtolower(trim($platform));
        if (isset($this->platformPayload[$platform]) && is_array($this->platformPayload[$platform])) {
            return $this->platformPayload[$platform];
        }

        return match ($this->type) {
            'markdown' => $this->markdownPayload($platform),
            'image' => $this->imagePayload($platform),
            'video' => $this->videoPayload($platform),
            'card' => $this->cardPayload($platform),
            default => $this->textPayload($platform),
        };
    }

    // 文本消息平台 payload
    private function textPayload(string $platform): array
    {
        return match ($platform) {
            'dingtalk' => $this->dingtalkTextPayload(),
            'wecom' => [
                'msgtype' => 'text',
                'text' => ['content' => $this->text],
            ],
            'feishu' => [
                'msg_type' => 'text',
                'content' => ['text' => $this->text],
            ],
            'qq_bot' => [
                'content' => $this->text,
            ],
            default => ['text' => $this->text],
        };
    }

    // Markdown 消息平台 payload
    private function markdownPayload(string $platform): array
    {
        return match ($platform) {
            'dingtalk' => $this->dingtalkMarkdownPayload(),
            'wecom' => [
                'msgtype' => 'markdown',
                'markdown' => ['content' => $this->text],
            ],
            'feishu' => [
                'msg_type' => 'post',
                'content' => [
                    'post' => [
                        'zh_cn' => [
                            'title' => $this->title ?: '消息',
                            'content' => [[
                                ['tag' => 'text', 'text' => $this->text],
                            ]],
                        ],
                    ],
                ],
            ],
            'qq_bot' => [
                'content' => $this->text,
            ],
            default => ['text' => $this->text],
        };
    }

    // 图片消息平台 payload（不支持原生图片的平台自动降级为文本链接）
    private function imagePayload(string $platform): array
    {
        $content = trim(($this->text !== '' ? $this->text : '图片') . ' ' . $this->imageUrl);

        return match ($platform) {
            'dingtalk' => [
                'msgtype' => 'image',
                'image' => ['picURL' => $this->imageUrl],
            ],
            'wecom' => [
                'msgtype' => 'text',
                'text' => ['content' => $content],
            ],
            'feishu' => [
                'msg_type' => 'text',
                'content' => ['text' => $content],
            ],
            'qq_bot' => [
                'content' => $content,
            ],
            default => [
                'text' => $content,
            ],
        };
    }

    // 视频消息平台 payload（不支持原生视频的平台自动降级为文本链接）
    private function videoPayload(string $platform): array
    {
        $content = trim(($this->text !== '' ? $this->text : '视频') . ' ' . $this->videoUrl);

        return match ($platform) {
            'dingtalk' => [
                'msgtype' => 'link',
                'link' => [
                    'title' => $this->text !== '' ? $this->text : '视频',
                    'text' => $this->videoUrl,
                    'messageUrl' => $this->videoUrl,
                ],
            ],
            'wecom' => [
                'msgtype' => 'text',
                'text' => ['content' => $content],
            ],
            'feishu' => [
                'msg_type' => 'text',
                'content' => ['text' => $content],
            ],
            'qq_bot' => [
                'content' => $content,
            ],
            default => [
                'text' => $content,
            ],
        };
    }

    // 卡片消息平台 payload（不支持平台降级为文本）
    private function cardPayload(string $platform): array
    {
        return match ($platform) {
            'feishu' => [
                'msg_type' => 'interactive',
                'card' => $this->card,
            ],
            'dingtalk' => [
                'msgtype' => 'actionCard',
                'actionCard' => $this->card,
            ],
            default => $this->textPayload($platform) + [
                'card' => $this->card,
            ],
        };
    }

    private function dingtalkTextPayload(): array
    {
        $payload = [
            'msgtype' => 'text',
            'text' => ['content' => $this->text],
        ];
        if ($this->atUserIds) {
            $payload['at'] = [
                'atUserIds' => $this->atUserIds,
                'isAtAll' => false,
            ];
        }
        return $payload;
    }

    private function dingtalkMarkdownPayload(): array
    {
        $payload = [
            'msgtype' => 'markdown',
            'markdown' => [
                'title' => $this->title ?: '消息',
                'text' => $this->text,
            ],
        ];
        if ($this->atUserIds) {
            $payload['at'] = [
                'atUserIds' => $this->atUserIds,
                'isAtAll' => false,
            ];
        }
        return $payload;
    }
}
