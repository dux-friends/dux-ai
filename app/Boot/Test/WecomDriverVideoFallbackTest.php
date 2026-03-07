<?php

use App\Boot\Models\BootBot;
use App\Boot\Service\Driver\WecomDriver;
use App\Boot\Service\Message;

it('企业微信视频发送失败时驱动内自动降级为文本发送', function () {
    $driver = new class extends WecomDriver
    {
        /** @var array<int, array<string, mixed>> */
        public array $requests = [];

        protected function requestJson(string $url, array $payload, array $headers = [], array $query = [], string $method = 'POST', int $timeout = 10): array
        {
            $this->requests[] = [
                'url' => $url,
                'payload' => $payload,
                'query' => $query,
                'method' => $method,
            ];

            if (str_contains($url, 'gettoken')) {
                return [
                    'errcode' => 0,
                    'access_token' => 'token_x',
                ];
            }

            $msgType = (string)($payload['msgtype'] ?? '');
            if ($msgType === 'video') {
                return [
                    'errcode' => 40001,
                    'errmsg' => 'mock video send failed',
                ];
            }

            return [
                'errcode' => 0,
                'errmsg' => 'ok',
            ];
        }
    };

    $bot = BootBot::query()->create([
        'name' => '企微',
        'code' => 'wecom_video_fallback_case',
        'platform' => 'wecom',
        'enabled' => true,
        'config' => [
            'corp_id' => 'corp_x',
            'app_secret' => 'secret_x',
            'agent_id' => 100001,
        ],
    ]);

    $message = Message::video('https://example.com/video.mp4', '视频已生成')
        ->conversationId('user_x')
        ->meta([
            'media_id' => 'media_x',
        ]);

    $result = $driver->send($bot, $message);

    expect($result['errcode'] ?? null)->toBe(0);
    expect(count($driver->requests))->toBe(3);
    expect($driver->requests[1]['payload']['msgtype'] ?? null)->toBe('video');
    expect($driver->requests[2]['payload']['msgtype'] ?? null)->toBe('text');
    expect((string)($driver->requests[2]['payload']['text']['content'] ?? ''))->toContain('https://example.com/video.mp4');
});

