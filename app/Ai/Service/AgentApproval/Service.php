<?php

declare(strict_types=1);

namespace App\Ai\Service\AgentApproval;

use App\Ai\Models\AiAgent;
use App\Ai\Models\AiAgentApproval;
use App\Ai\Models\AiAgentSession;
use App\Ai\Service\Agent\MessageStore;
use App\Ai\Support\AiRuntime;
use Core\Handlers\ExceptionBusiness;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ActionDecision;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;

final class Service
{
    public static function workflowId(int $sessionId, int $userMessageId): string
    {
        return sprintf('agent_approval_%d_%d', $sessionId, $userMessageId > 0 ? $userMessageId : time());
    }

    public static function createFromInterrupt(
        WorkflowInterrupt $interrupt,
        AiAgent $agent,
        AiAgentSession $session,
        int $userMessageId = 0,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): AiAgentApproval {
        $request = $interrupt->getRequest();
        if (!$request instanceof ApprovalRequest) {
            throw new ExceptionBusiness('审批中断请求类型不正确');
        }

        [$toolName, $actionName, $riskLevel, $summary, $requestJson] = self::extractRequestMeta($request);
        $assistant = MessageStore::appendMessage(
            (int)$agent->id,
            (int)$session->id,
            'assistant',
            '',
            [
                '_approval' => [
                    'id' => 0,
                    'workflow_id' => $interrupt->getResumeToken(),
                    'risk_level' => $riskLevel,
                    'tool_name' => $toolName,
                    'action_name' => $actionName,
                    'status' => 'pending',
                    'summary' => $summary,
                    'request' => $requestJson,
                ],
            ]
        );

        $approval = AiAgentApproval::query()->updateOrCreate([
            'workflow_id' => $interrupt->getResumeToken(),
        ], [
            'agent_id' => (int)$agent->id,
            'session_id' => (int)$session->id,
            'user_message_id' => $userMessageId ?: null,
            'assistant_message_id' => (int)$assistant->id,
            'tool_name' => $toolName,
            'action_name' => $actionName,
            'risk_level' => $riskLevel,
            'status' => 'pending',
            'source_type' => $sourceType,
            'source_id' => $sourceId ?: null,
            'summary' => $summary,
            'request_json' => $requestJson,
        ]);

        self::syncAssistantMessage($approval, 'pending');

        if ($userMessageId > 0) {
            MessageStore::markUserMessageApprovalRequired($userMessageId, (int)$approval->id, $summary);
        }

        return $approval;
    }

    public static function requireById(int $id): AiAgentApproval
    {
        /** @var AiAgentApproval|null $approval */
        $approval = AiAgentApproval::query()->find($id);
        if (!$approval) {
            throw new ExceptionBusiness('审批记录不存在');
        }
        return $approval;
    }

    public static function findPendingBySession(int $sessionId): ?AiAgentApproval
    {
        /** @var AiAgentApproval|null $approval */
        $approval = AiAgentApproval::query()
            ->where('session_id', $sessionId)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->first();

        if (!$approval) {
            return null;
        }

        if ($approval->expires_at && $approval->expires_at->isPast()) {
            $approval->status = 'expired';
            $approval->save();
            self::syncAssistantMessage($approval, 'expired');
            return null;
        }

        return $approval;
    }

    public static function assertPending(AiAgentApproval $approval): void
    {
        $status = (string)$approval->status;
        if ($status === 'pending' && !$approval->expires_at) {
            return;
        }
        if ($approval->expires_at && $approval->expires_at->isPast() && $status === 'pending') {
            $approval->status = 'expired';
            $approval->save();
            self::syncAssistantMessage($approval, 'expired');
            throw new ExceptionBusiness('该审批已过期');
        }
        if ($status === 'approved' || $status === 'rejected' || $status === 'expired' || $status === 'canceled') {
            throw new ExceptionBusiness($status === 'expired' ? '该审批已过期' : '该审批已处理');
        }
    }

    public static function claimApprove(AiAgentApproval $approval, ?string $userType = null, ?int $userId = null, ?string $feedback = null): AiAgentApproval
    {
        return self::claimDecision($approval, 'approved', $userType, $userId, $feedback);
    }

    public static function claimReject(AiAgentApproval $approval, ?string $userType = null, ?int $userId = null, ?string $feedback = null): AiAgentApproval
    {
        return self::claimDecision($approval, 'rejected', $userType, $userId, $feedback);
    }

    public static function buildResumeRequest(AiAgentApproval $approval, string $decision, ?string $feedback = null): ApprovalRequest
    {
        $interrupt = self::loadInterrupt($approval);
        $request = $interrupt->getRequest();
        if (!$request instanceof ApprovalRequest) {
            throw new ExceptionBusiness('审批请求恢复失败');
        }

        foreach ($request->getActions() as $action) {
            if (!$action instanceof Action) {
                continue;
            }
            if ($decision === 'approve') {
                $action->approve($feedback);
            } else {
                $action->reject($feedback);
            }
        }

        return $request;
    }

    public static function loadInterrupt(AiAgentApproval $approval): WorkflowInterrupt
    {
        return ApprovalPersistenceStore::make()->load((string)$approval->workflow_id);
    }

    public static function markApproved(AiAgentApproval $approval, ?string $userType = null, ?int $userId = null, ?string $feedback = null): void
    {
        $approval->status = 'approved';
        $approval->feedback = $feedback;
        $approval->approved_by_type = $userType;
        $approval->approved_by = $userId ?: null;
        $approval->approved_at = now();
        $approval->save();
        self::syncAssistantMessage($approval, 'approved');
    }

    public static function markRejected(AiAgentApproval $approval, ?string $userType = null, ?int $userId = null, ?string $feedback = null): void
    {
        $approval->status = 'rejected';
        $approval->feedback = $feedback;
        $approval->rejected_by_type = $userType;
        $approval->rejected_by = $userId ?: null;
        $approval->rejected_at = now();
        $approval->save();
        self::syncAssistantMessage($approval, 'rejected');
    }

    private static function claimDecision(AiAgentApproval $approval, string $targetStatus, ?string $userType = null, ?int $userId = null, ?string $feedback = null): AiAgentApproval
    {
        self::assertPending($approval);

        $time = now();
        $update = [
            'status' => $targetStatus,
            'feedback' => $feedback,
            'updated_at' => $time,
        ];

        if ($targetStatus === 'approved') {
            $update['approved_by_type'] = $userType;
            $update['approved_by'] = $userId ?: null;
            $update['approved_at'] = $time;
        } else {
            $update['rejected_by_type'] = $userType;
            $update['rejected_by'] = $userId ?: null;
            $update['rejected_at'] = $time;
        }

        $affected = AiAgentApproval::query()
            ->where('id', (int)$approval->id)
            ->where('status', 'pending')
            ->update($update);

        if ($affected < 1) {
            $fresh = self::requireById((int)$approval->id);
            self::assertPending($fresh);
            throw new ExceptionBusiness('该审批已处理');
        }

        $fresh = self::requireById((int)$approval->id);
        self::syncAssistantMessage($fresh, $targetStatus);
        return $fresh;
    }

    public static function syncAssistantMessage(AiAgentApproval $approval, string $status): void
    {
        if (!(int)$approval->assistant_message_id) {
            return;
        }
        $message = \App\Ai\Models\AiAgentMessage::query()->find((int)$approval->assistant_message_id);
        if (!$message) {
            return;
        }
        $payload = is_array($message->payload ?? null) ? ($message->payload ?? []) : [];
        $approvalPayload = [
            'id' => (int)$approval->id,
            'workflow_id' => (string)$approval->workflow_id,
            'risk_level' => (string)$approval->risk_level,
            'tool_name' => $approval->tool_name,
            'action_name' => $approval->action_name,
            'status' => $status,
            'summary' => (string)($approval->summary ?? ''),
            'request' => is_array($approval->request_json) ? ($approval->request_json ?? []) : [],
            'display_value' => self::resolveDisplayValue(is_array($approval->request_json) ? ($approval->request_json ?? []) : []),
        ];
        $payload['_approval'] = $approvalPayload;
        $message->payload = $payload;
        $message->content = '';
        $message->save();
    }

    /**
     * @return array{0:string,1:?string,2:string,3:string,4:array<string,mixed>}
     */
    private static function extractRequestMeta(ApprovalRequest $request): array
    {
        $actions = $request->getActions();
        $toolName = 'tool';
        $actionName = null;
        $riskLevel = 'dangerous';
        $summary = trim($request->getMessage()) !== '' ? trim($request->getMessage()) : '需要人工审批后才能继续执行';
        $requestJson = [
            'message' => $request->getMessage(),
            'actions' => [],
        ];

        foreach ($actions as $action) {
            if (!$action instanceof Action) {
                continue;
            }
            $parsed = self::parseActionDescription((string)$action->description);
            $toolName = (string)($parsed['tool_name'] ?? $toolName);
            $actionName = $parsed['action_name'] ?? $actionName;
            $riskLevel = (string)($parsed['risk_level'] ?? $riskLevel);
            $requestJson['actions'][] = [
                'id' => $action->id,
                'name' => $action->name,
                'description' => $action->description,
                'decision' => $action->decision->value,
                'parsed' => $parsed,
            ];
        }

        if ($actionName) {
            $summary = sprintf('危险动作 [%s] 需要人工审批后才能执行', $actionName);
        }

        return [$toolName, $actionName, $riskLevel, $summary, $requestJson];
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseActionDescription(string $description): array
    {
        $decoded = json_decode($description, true);
        if (!is_array($decoded)) {
            return [];
        }
        $toolName = trim((string)($decoded['tool_name'] ?? 'tool'));
        $actionName = trim((string)($decoded['action'] ?? '')) ?: null;
        $riskLevel = trim((string)($decoded['risk_level'] ?? 'guarded')) ?: 'guarded';
        return [
            'tool_name' => $toolName,
            'action_name' => $actionName,
            'risk_level' => $riskLevel,
            'payload' => is_array($decoded['inputs'] ?? null) ? ($decoded['inputs'] ?? []) : [],
        ];
    }

    private static function resolveDisplayValue(array $requestJson): ?string
    {
        $payload = $requestJson['actions'][0]['parsed']['payload'] ?? [];
        if (!is_array($payload)) {
            return null;
        }

        foreach (['command', 'url', 'selector', 'path'] as $field) {
            if (!empty($payload[$field]) && is_string($payload[$field])) {
                return trim($payload[$field]);
            }
            if (!empty($payload['payload'][$field]) && is_string($payload['payload'][$field])) {
                return trim($payload['payload'][$field]);
            }
        }

        return null;
    }

}
