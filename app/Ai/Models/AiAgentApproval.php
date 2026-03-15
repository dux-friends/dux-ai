<?php

declare(strict_types=1);

namespace App\Ai\Models;

use Core\Database\Attribute\AutoMigrate;
use Core\Database\Model;
use Illuminate\Database\Schema\Blueprint;

#[AutoMigrate]
class AiAgentApproval extends Model
{
    protected $table = 'ai_agent_approvals';

    protected $casts = [
        'request_json' => 'array',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function migration(Blueprint $table): void
    {
        $table->id();
        $table->string('workflow_id')->unique()->comment('Neuron工作流/恢复ID');
        $table->unsignedBigInteger('agent_id')->nullable()->comment('智能体ID');
        $table->unsignedBigInteger('session_id')->nullable()->comment('会话ID');
        $table->unsignedBigInteger('user_message_id')->nullable()->comment('触发审批的用户消息ID');
        $table->unsignedBigInteger('assistant_message_id')->nullable()->comment('审批卡片消息ID');
        $table->string('tool_name')->nullable()->comment('工具名');
        $table->string('action_name')->nullable()->comment('动作名');
        $table->string('risk_level')->default('dangerous')->comment('风险等级');
        $table->string('status')->default('pending')->comment('pending/approved/rejected/expired/canceled/running');
        $table->string('source_type')->nullable()->comment('审批来源类型');
        $table->unsignedBigInteger('source_id')->nullable()->comment('审批来源ID');
        $table->text('summary')->nullable()->comment('审批摘要');
        $table->json('request_json')->nullable()->comment('审批请求结构化数据');
        $table->text('feedback')->nullable()->comment('审批反馈');
        $table->string('approved_by_type')->nullable()->comment('批准人类型');
        $table->unsignedBigInteger('approved_by')->nullable()->comment('批准人ID');
        $table->string('rejected_by_type')->nullable()->comment('拒绝人类型');
        $table->unsignedBigInteger('rejected_by')->nullable()->comment('拒绝人ID');
        $table->timestamp('approved_at')->nullable()->comment('批准时间');
        $table->timestamp('rejected_at')->nullable()->comment('拒绝时间');
        $table->timestamp('expires_at')->nullable()->comment('过期时间');
        $table->timestamps();
        $table->index(['session_id', 'status'], 'ai_agent_approval_session_status_idx');
    }

    public function transform(): array
    {
        return [
            'id' => (int)$this->id,
            'workflow_id' => (string)$this->workflow_id,
            'agent_id' => $this->agent_id ? (int)$this->agent_id : null,
            'session_id' => $this->session_id ? (int)$this->session_id : null,
            'user_message_id' => $this->user_message_id ? (int)$this->user_message_id : null,
            'assistant_message_id' => $this->assistant_message_id ? (int)$this->assistant_message_id : null,
            'tool_name' => $this->tool_name ? (string)$this->tool_name : null,
            'action_name' => $this->action_name ? (string)$this->action_name : null,
            'risk_level' => (string)$this->risk_level,
            'status' => (string)$this->status,
            'summary' => (string)($this->summary ?? ''),
            'request_json' => is_array($this->request_json) ? $this->request_json : [],
            'feedback' => $this->feedback ? (string)$this->feedback : null,
            'approved_at' => $this->approved_at?->toDateTimeString(),
            'rejected_at' => $this->rejected_at?->toDateTimeString(),
            'expires_at' => $this->expires_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
