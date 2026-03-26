<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Event\AiToolkitEvent;
use Core\Event\Attribute\Listener;

final class ToolkitVideoListener
{
    #[Listener(name: 'ai.toolkit')]
    public function handle(AiToolkitEvent $event): void
    {
        $event->register([
            'code' => 'video',
            'label' => '视频生成',
            'description' => '视频生成、任务查询和任务取消能力集合，适合给智能体一次挂载完整的视频任务能力',
            'icon' => 'i-tabler:video',
            'color' => 'primary',
            'agent_selectable' => true,
            'defaults' => [
                'delay_seconds' => 0,
                'poll_interval_seconds' => 30,
                'timeout_minutes' => 30,
            ],
            'settings' => self::sharedSettings(),
            'items' => [
                [
                    'code' => 'video_generate',
                    'inherit_settings' => false,
                    'settings' => self::overrideSettings(),
                ],
                [
                    'code' => 'video_task_query',
                    'inherit_settings' => false,
                ],
                [
                    'code' => 'video_task_cancel',
                    'inherit_settings' => false,
                ],
            ],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function sharedSettings(): array
    {
        return [
            [
                'name' => 'model_id',
                'label' => '默认视频模型',
                'component' => 'dux-select',
                'componentProps' => [
                    'path' => 'ai/flow/modelOptions',
                    'params' => ['type' => 'video'],
                    'labelField' => 'label',
                    'valueField' => 'id',
                    'descField' => 'desc',
                ],
            ],
            [
                'name' => 'delay_seconds',
                'label' => '默认首次查询延迟(秒)',
                'component' => 'number',
                'defaultValue' => 0,
                'componentProps' => [
                    'min' => 0,
                    'step' => 1,
                ],
            ],
            [
                'name' => 'poll_interval_seconds',
                'label' => '默认轮询间隔(秒)',
                'component' => 'number',
                'defaultValue' => 30,
                'componentProps' => [
                    'min' => 1,
                    'step' => 1,
                ],
            ],
            [
                'name' => 'timeout_minutes',
                'label' => '默认超时(分钟)',
                'component' => 'number',
                'defaultValue' => 30,
                'componentProps' => [
                    'min' => 1,
                    'step' => 1,
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function overrideSettings(): array
    {
        return [
            [
                'name' => 'model_id',
                'label' => '视频模型',
                'component' => 'dux-select',
                'componentProps' => [
                    'path' => 'ai/flow/modelOptions',
                    'params' => ['type' => 'video'],
                    'labelField' => 'label',
                    'valueField' => 'id',
                    'descField' => 'desc',
                ],
            ],
            [
                'name' => 'delay_seconds',
                'label' => '首次查询延迟(秒)',
                'component' => 'number',
                'defaultValue' => 0,
                'componentProps' => [
                    'min' => 0,
                    'step' => 1,
                ],
            ],
            [
                'name' => 'poll_interval_seconds',
                'label' => '轮询间隔(秒)',
                'component' => 'number',
                'defaultValue' => 30,
                'componentProps' => [
                    'min' => 1,
                    'step' => 1,
                ],
            ],
            [
                'name' => 'timeout_minutes',
                'label' => '超时(分钟)',
                'component' => 'number',
                'defaultValue' => 30,
                'componentProps' => [
                    'min' => 1,
                    'step' => 1,
                ],
            ],
        ];
    }
}
