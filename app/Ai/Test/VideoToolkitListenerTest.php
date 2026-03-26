<?php

declare(strict_types=1);

use App\Ai\Event\AiToolkitEvent;
use App\Ai\Listener\ToolkitVideoListener;

it('视频工具包：已注册为可选的智能体工具包', function () {
    $event = new AiToolkitEvent();
    (new ToolkitVideoListener())->handle($event);
    $toolkit = $event->getRegistry()['video'] ?? null;

    expect($toolkit)->toBeArray()
        ->and($toolkit['agent_selectable'] ?? null)->toBeTrue()
        ->and($toolkit['label'] ?? null)->toBe('视频生成')
        ->and($toolkit['items'] ?? [])->toHaveCount(3);
});
