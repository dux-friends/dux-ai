<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Listener\ToolkitVideoListener;
use App\Ai\Listener\ToolkitRegistryListener;
use App\System\Event\ManageEvent;
use App\Ai\Service\Parse\ParseFactory;
use Core\App as CoreApp;
use Core\App\AppExtend;
use Core\Bootstrap;

class App extends AppExtend
{
    public function register(Bootstrap $app): void
    {
        ParseFactory::migrateLegacyProviders();

        $toolkitRegistry = new ToolkitRegistryListener();
        CoreApp::event()->addListener('ai.toolkit', [$toolkitRegistry, 'handle']);

        $toolkitVideo = new ToolkitVideoListener();
        CoreApp::event()->addListener('ai.toolkit', [$toolkitVideo, 'handle']);

        CoreApp::event()->addListener('system.manage', static function (ManageEvent $event) {
            $manages = $event->getManages();
            foreach ($manages as $index => $item) {
                $tools = array_values(array_filter(
                    (array)($item['tools'] ?? []),
                    static fn (mixed $tool): bool => (string)(is_array($tool) ? ($tool['key'] ?? '') : '') !== 'ai_assistant'
                ));
                $tools[] = [
                    'key' => 'ai_assistant',
                    'label' => 'AI 助手',
                    'icon' => 'i-tabler:robot',
                    'type' => 'modal',
                    'loader' => 'Ai/Agent/manageModal',
                    'title' => 'AI 助手',
                    'width' => 1360,
                ];
                $item['apiPath'] = [
                    ...((array)($item['apiPath'] ?? [])),
                    'ai' => '/admin/editorAi',
                ];
                $item['tools'] = $tools;
                $manages[$index] = $item;
            }
            $event->setManages($manages);
        });
    }
}
