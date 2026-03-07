<?php

declare(strict_types=1);

namespace App\Boot\Models;

use Core\Database\Attribute\AutoMigrate;
use Core\Database\Model;
use Illuminate\Database\Schema\Blueprint;

#[AutoMigrate]
class BootMessageLog extends Model
{
    protected $table = 'boot_message_log';

    protected $casts = [
        'raw_payload' => 'array',
    ];

    public function migration(Blueprint $table): void
    {
        $table->id();
        $table->unsignedBigInteger('bot_id')->nullable()->comment('机器人ID');
        $table->string('direction')->comment('方向 inbound/outbound');
        $table->string('platform')->comment('平台');
        $table->string('event_id')->nullable()->comment('事件ID');
        $table->string('conversation_id')->nullable()->comment('会话ID');
        $table->string('sender_id')->nullable()->comment('发送者ID');
        $table->string('sender_name')->nullable()->comment('发送者名称');
        $table->string('message_type')->default('text')->comment('消息类型');
        $table->longText('content')->nullable()->comment('消息内容');
        $table->json('raw_payload')->nullable()->comment('原始数据');
        $table->string('status')->default('ok')->comment('状态');
        $table->text('error')->nullable()->comment('错误信息');
        $table->timestamps();

        $table->index(['bot_id', 'direction', 'created_at']);
        $table->index(['platform', 'created_at']);
        $table->index('event_id');
    }

    public function transform(): array
    {
        return [
            'id' => $this->id,
            'bot_id' => $this->bot_id,
            'direction' => $this->direction,
            'platform' => $this->platform,
            'event_id' => $this->event_id,
            'conversation_id' => $this->conversation_id,
            'sender_id' => $this->sender_id,
            'sender_name' => $this->sender_name,
            'message_type' => $this->message_type,
            'content' => $this->content,
            'raw_payload' => is_array($this->raw_payload) ? $this->raw_payload : [],
            'status' => $this->status,
            'error' => $this->error,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}

