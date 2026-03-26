<?php

declare(strict_types=1);

use App\Ai\Models\AiAgent;
use App\Ai\Service\Capability\Service as CapabilityService;
use App\Ai\Service\Neuron\Agent\ToolFactory;
use App\Ai\Service\Tool;
use App\Ai\Service\Tool\Service as ToolService;
use App\Ai\Service\Toolkit;
use App\Ai\Service\Toolkit\Service as ToolkitRegistryService;
use App\Ai\Support\AiRuntime;

function newToolkitAiAgentWithoutCtor(): AiAgent
{
    $ref = new ReflectionClass(AiAgent::class);
    /** @var AiAgent $agent */
    $agent = $ref->newInstanceWithoutConstructor();
    return $agent;
}

it('智能体工具包：共享默认和单项覆盖会展开为实际工具，且显式单工具优先', function () {
    Tool::reset();
    Toolkit::reset();

    $capabilityService = new CapabilityService(AiRuntime::instance());
    $capBooted = new ReflectionProperty($capabilityService, 'booted');
    $capRegistry = new ReflectionProperty($capabilityService, 'registry');
    $capBooted->setValue($capabilityService, true);
    $capRegistry->setValue($capabilityService, [
        'content_article_list' => [
            'code' => 'content_article_list',
            'label' => '文章列表',
            'description' => '文章列表',
            'types' => ['agent'],
            'tool' => ['function' => 'content_article_list'],
            'schema' => ['type' => 'object', 'properties' => ['keyword' => ['type' => 'string']]],
            'settings' => [
                ['name' => 'class_id', 'label' => '分类', 'component' => 'number'],
                ['name' => 'status', 'label' => '状态', 'component' => 'switch'],
                ['name' => 'limit', 'label' => '条数', 'component' => 'number'],
            ],
            'handler' => static fn (array $params, $context) => $params,
        ],
        'content_article_create' => [
            'code' => 'content_article_create',
            'label' => '创建文章',
            'description' => '创建文章',
            'types' => ['agent'],
            'tool' => ['function' => 'content_article_create'],
            'schema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]],
            'settings' => [
                ['name' => 'class_id', 'label' => '分类', 'component' => 'number'],
                ['name' => 'status', 'label' => '状态', 'component' => 'switch'],
            ],
            'handler' => static fn (array $params, $context) => $params,
        ],
    ]);

    Tool::setService(new ToolService($capabilityService));

    $toolkitService = new ToolkitRegistryService(AiRuntime::instance());
    $toolkitBooted = new ReflectionProperty($toolkitService, 'booted');
    $toolkitRegistry = new ReflectionProperty($toolkitService, 'registry');
    $toolkitBooted->setValue($toolkitService, true);
    $toolkitRegistry->setValue($toolkitService, [
        'content' => [
            'code' => 'content',
            'label' => '内容管理',
            'description' => '内容工具包',
            'items' => [
                ['code' => 'content_article_list', 'label' => '文章列表'],
                ['code' => 'content_article_create', 'label' => '创建文章'],
            ],
        ],
    ]);

    Toolkit::setService($toolkitService);

    $agent = newToolkitAiAgentWithoutCtor();
    $agent->id = 1;
    $agent->tools = [
        ['code' => 'content_article_create', 'description' => '单独绑定创建文章', 'status' => false],
    ];
    $agent->settings = [
        'toolkits' => [
            [
                'toolkit' => 'content',
                'config' => [
                    'class_id' => 9,
                    'status' => true,
                ],
                'overrides' => [
                    'content_article_list' => [
                        'limit' => 50,
                    ],
                    'content_article_create' => [
                        'status' => true,
                    ],
                ],
            ],
        ],
    ];

    $built = ToolFactory::buildForAgent($agent);

    expect($built['map'])->toHaveKeys(['content_article_list', 'content_article_create'])
        ->and($built['map']['content_article_list']['class_id'])->toBe(9)
        ->and($built['map']['content_article_list']['status'])->toBeTrue()
        ->and($built['map']['content_article_list']['limit'])->toBe(50)
        ->and($built['map']['content_article_create']['status'])->toBeFalse();

    $listTool = collect($built['tools'])->first(fn ($tool) => $tool->getName() === 'content_article_list');
    expect($listTool)->not->toBeNull();

    $listTool->setInputs(['keyword' => 'AI']);
    $listTool->execute();
    $result = json_decode($listTool->getResult(), true);

    expect($result)->toBe([
        'class_id' => 9,
        'status' => true,
        'limit' => 50,
        '__session_id' => 0,
        '__agent_id' => 1,
        'keyword' => 'AI',
    ]);

    Tool::reset();
    Toolkit::reset();
});
