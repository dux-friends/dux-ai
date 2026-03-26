<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Event\AiToolkitEvent;
use App\Ai\Service\Neuron\Mcp\McpToolkitFactory;
use App\Ai\Service\Neuron\Toolkit\SystemToolkit;
use Core\Event\Attribute\Listener;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;

final class ToolkitRegistryListener
{
    #[Listener(name: 'ai.toolkit')]
    public function handle(AiToolkitEvent $event): void
    {
        $event->register([
            'code' => 'system',
            'label' => '系统工具包',
            'description' => '系统内置基础工具集合',
            'icon' => 'i-tabler:tool',
            'color' => 'primary',
            'handler' => static fn () => SystemToolkit::make(),
        ]);

        $event->register([
            'code' => 'calculator',
            'label' => '计算器工具包',
            'description' => '系统内置计算器工具集合',
            'icon' => 'i-tabler:calculator',
            'color' => 'info',
            'handler' => static fn () => CalculatorToolkit::make(),
        ]);

        $event->register([
            'code' => 'mcp',
            'label' => 'MCP 工具包',
            'description' => '通过 MCP 接入远端工具集合',
            'icon' => 'i-tabler:plug-connected',
            'color' => 'warning',
            'handler' => static function (array $item) {
                $config = is_array($item['config'] ?? null) ? array_merge($item, $item['config']) : $item;
                return McpToolkitFactory::tools($config);
            },
        ]);
    }
}
