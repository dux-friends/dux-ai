<?php

use App\Ai\Service\Neuron\Agent\ModelRateLimiter;
use App\Ai\Service\Neuron\Agent\TokenEstimator;

it('TokenEstimator：估算聊天预算并包含工具与安全余量', function () {
    $budget = TokenEstimator::estimateChatBudget(
        '你是一个工具调用助手',
        [
            ['role' => 'user', 'content' => '读取当前系统信息'],
            ['role' => 'assistant', 'content' => '正在处理'],
        ],
        [
            'tool_action' => [
                'label' => '工具动作',
                'description' => '调用工具动作',
                'schema' => ['type' => 'object'],
            ],
        ],
        [],
        []
    );

    expect($budget['input_tokens'])->toBeGreaterThan(0)
        ->and($budget['output_tokens'])->toBe(600)
        ->and($budget['tool_overhead'])->toBeGreaterThanOrEqual(300)
        ->and($budget['safety_margin'])->toBeGreaterThanOrEqual(200)
        ->and($budget['total'])->toBeGreaterThan($budget['input_tokens']);
});

it('ModelRateLimiter：可预占并按实际 token 回填', function () {
    $modelKey = 'test-provider:test-model:remote';
    ModelRateLimiter::clear($modelKey);

    $reservation = ModelRateLimiter::acquireForAgent(new class extends \App\Ai\Models\AiAgent {
        public function __construct()
        {
            $this->setRelation('model', new class extends \App\Ai\Models\AiModel {
                public function __construct()
                {
                    $this->options = ['rate_limit' => ['tpm' => 1000, 'max_wait_ms' => 0]];
                    $this->code = 'test-model';
                    $this->model = 'remote';
                    $this->provider_id = 1;
                    $this->setRelation('provider', new class extends \App\Ai\Models\AiProvider {
                        public function __construct()
                        {
                            $this->code = 'test-provider';
                        }
                    });
                }
            });
        }
    }, 300);

    expect($reservation['enabled'])->toBeTrue()
        ->and($reservation['requested_tokens'])->toBe(300);

    ModelRateLimiter::finalize($reservation, 180);
    $snapshot = ModelRateLimiter::snapshot($reservation['model_key']);

    expect($snapshot['used_tokens'])->toBe(180)
        ->and($snapshot['reservations'])->toBe(1);

    ModelRateLimiter::clear($reservation['model_key']);
});
