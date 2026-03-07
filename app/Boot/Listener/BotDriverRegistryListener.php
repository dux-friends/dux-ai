<?php

declare(strict_types=1);

namespace App\Boot\Listener;

use App\Boot\Event\BotDriverEvent;
use App\Boot\Service\Driver\DingtalkDriver;
use App\Boot\Service\Driver\FeishuDriver;
use App\Boot\Service\Driver\QqBotDriver;
use App\Boot\Service\Driver\WecomDriver;
use Core\Event\Attribute\Listener;

final class BotDriverRegistryListener
{
    #[Listener(name: 'boot.bot.driver')]
    public function handle(BotDriverEvent $event): void
    {
        $event->register(new DingtalkDriver());
        $event->register(new FeishuDriver());
        $event->register(new QqBotDriver());
        $event->register(new WecomDriver());
    }
}
