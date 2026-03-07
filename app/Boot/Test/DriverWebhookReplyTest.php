<?php

use App\Boot\Models\BootBot;
use App\Boot\Service\DTO\InboundMessage;
use App\Boot\Service\Driver\DingtalkDriver;
use App\Boot\Service\Driver\FeishuDriver;
use App\Boot\Service\Driver\QqBotDriver;
use App\Boot\Service\Driver\WecomDriver;
use GuzzleHttp\Psr7\ServerRequest;

it('企业微信：ack_only 回调返回 success', function () {
    $bot = BootBot::query()->create([
        'name' => '企微',
        'code' => 'wecom_case',
        'platform' => 'wecom',
        'enabled' => true,
        'config' => [],
    ]);
    $message = new InboundMessage('wecom', 'e1', 'u1', 'u1', 'u1', 'hi', time(), []);
    $request = new ServerRequest('POST', 'https://example.com/boot/webhook/wecom_case');

    $result = (new WecomDriver())->handleWebhookReply($bot, $request, $message, 'ignored', true);
    expect($result)->toBe('success');
});

it('钉钉：ack_only 回调返回 ok', function () {
    $bot = BootBot::query()->create([
        'name' => '钉钉',
        'code' => 'dingtalk_case',
        'platform' => 'dingtalk',
        'enabled' => true,
        'config' => [],
    ]);
    $message = new InboundMessage('dingtalk', 'e1', 'c1', 's1', 's1', 'hi', time(), []);
    $request = new ServerRequest('POST', 'https://example.com/boot/webhook/dingtalk_case');

    $result = (new DingtalkDriver())->handleWebhookReply($bot, $request, $message, 'ignored', true);
    expect($result)->toBe(['ok' => true]);
});

it('QQ 机器人：op=13 验证事件由驱动返回签名响应', function () {
    if (!function_exists('sodium_crypto_sign_detached')) {
        test()->markTestSkipped('当前环境缺少 sodium 扩展');
    }

    $bot = BootBot::query()->create([
        'name' => 'QQ',
        'code' => 'qq_case',
        'platform' => 'qq_bot',
        'enabled' => true,
        'config' => [
            'app_secret' => 'test_secret',
        ],
    ]);
    $payload = [
        'op' => 13,
        'd' => [
            'plain_token' => 'plain_123',
            'event_ts' => '1700000000',
        ],
    ];
    $request = new ServerRequest('POST', 'https://example.com/boot/webhook/qq_case');

    $result = (new QqBotDriver())->resolveWebhookPayloadResponse($bot, $payload, $request);
    expect($result)->toBeArray()
        ->and($result['plain_token'] ?? null)->toBe('plain_123')
        ->and((string)($result['signature'] ?? ''))->not->toBe('');
});

it('飞书：url_verification 事件由驱动直接返回 challenge', function () {
    $bot = BootBot::query()->create([
        'name' => '飞书',
        'code' => 'feishu_case',
        'platform' => 'feishu',
        'enabled' => true,
        'config' => [],
    ]);
    $payload = [
        'type' => 'url_verification',
        'challenge' => 'abc123',
    ];
    $request = new ServerRequest('POST', 'https://example.com/boot/webhook/feishu_case');

    $result = (new FeishuDriver())->resolveWebhookPayloadResponse($bot, $payload, $request);
    expect($result)->toBe(['challenge' => 'abc123']);
});
