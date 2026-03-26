<?php

declare(strict_types=1);

use App\Ai\Service\Capability;
use App\Ai\Service\Capability\Service as CapabilityService;
use App\Ai\Service\Toolkit\Service as ToolkitRegistryService;
use App\Ai\Support\AiRuntime;

it('工具包注册：可关闭单项能力配置继承，只保留运行时入参', function () {
    $capabilityService = new CapabilityService(AiRuntime::instance());
    $capBooted = new ReflectionProperty($capabilityService, 'booted');
    $capRegistry = new ReflectionProperty($capabilityService, 'registry');
    $capBooted->setValue($capabilityService, true);
    $capRegistry->setValue($capabilityService, [
        'bind_node' => [
            'code' => 'bind_node',
            'label' => '绑定节点',
            'description' => '绑定节点',
            'settings' => [
                ['name' => 'target', 'label' => '节点标识', 'component' => 'text'],
                ['name' => 'mode', 'label' => '模式', 'component' => 'select'],
            ],
            'defaults' => [
                'mode' => 'bind',
            ],
        ],
    ]);
    Capability::setService($capabilityService);

    $service = new ToolkitRegistryService(AiRuntime::instance());
    $method = new ReflectionMethod($service, 'normalizeMeta');

    $meta = $method->invoke($service, [
        'code' => 'desktop',
        'items' => [
            [
                'code' => 'bind_node',
                'inherit_settings' => false,
            ],
        ],
    ]);

    expect($meta['items'][0]['settings'] ?? null)->toBe([])
        ->and($meta['items'][0]['defaults'] ?? null)->toBe([
            'mode' => 'bind',
        ]);

    Capability::reset();
});

it('工具包注册：可用自定义白名单配置替换能力原始配置项', function () {
    $capabilityService = new CapabilityService(AiRuntime::instance());
    $capBooted = new ReflectionProperty($capabilityService, 'booted');
    $capRegistry = new ReflectionProperty($capabilityService, 'registry');
    $capBooted->setValue($capabilityService, true);
    $capRegistry->setValue($capabilityService, [
        'video_generate' => [
            'code' => 'video_generate',
            'label' => '视频生成',
            'description' => '视频生成',
            'settings' => [
                ['name' => 'model_id', 'label' => '视频模型', 'component' => 'dux-select'],
                ['name' => 'image_url', 'label' => '首帧图片URL', 'component' => 'text'],
                ['name' => 'delay_seconds', 'label' => '首次查询延迟(秒)', 'component' => 'number'],
            ],
            'defaults' => [
                'delay_seconds' => 0,
                'poll_interval_seconds' => 30,
            ],
        ],
    ]);
    Capability::setService($capabilityService);

    $service = new ToolkitRegistryService(AiRuntime::instance());
    $method = new ReflectionMethod($service, 'normalizeMeta');

    $meta = $method->invoke($service, [
        'code' => 'video',
        'items' => [
            [
                'code' => 'video_generate',
                'inherit_settings' => false,
                'settings' => [
                    ['name' => 'model_id', 'label' => '视频模型', 'component' => 'dux-select'],
                    ['name' => 'delay_seconds', 'label' => '首次查询延迟(秒)', 'component' => 'number'],
                ],
            ],
        ],
    ]);

    expect(array_column($meta['items'][0]['settings'] ?? [], 'name'))->toBe([
        'model_id',
        'delay_seconds',
    ])
        ->and(array_column($meta['items'][0]['settings'] ?? [], 'name'))->not->toContain('image_url');

    Capability::reset();
});
