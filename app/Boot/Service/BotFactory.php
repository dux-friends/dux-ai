<?php

declare(strict_types=1);

namespace App\Boot\Service;

use App\Boot\Service\Contracts\BotDriverInterface;
use App\Boot\Event\BotDriverEvent;
use App\Boot\Service\Driver\DingtalkDriver;
use App\Boot\Service\Driver\FeishuDriver;
use App\Boot\Service\Driver\QqBotDriver;
use App\Boot\Service\Driver\WecomDriver;
use Core\App;
use Core\Handlers\ExceptionBusiness;

class BotFactory
{
    /**
     * @var array<string, BotDriverInterface>
     */
    private array $drivers = [];

    public function __construct()
    {
        $this->register(new DingtalkDriver());
        $this->register(new FeishuDriver());
        $this->register(new QqBotDriver());
        $this->register(new WecomDriver());

        $event = new BotDriverEvent();
        foreach ($this->drivers as $driver) {
            $event->register($driver);
        }
        App::event()->dispatch($event, 'boot.bot.driver');
        $this->drivers = $event->getDrivers();
    }

    // 手动注册驱动实现
    public function register(BotDriverInterface $driver): void
    {
        $this->drivers[$driver->platform()] = $driver;
    }

    // 按平台获取驱动实例
    public function driver(string $platform): BotDriverInterface
    {
        $platform = strtolower(trim($platform));
        if (!isset($this->drivers[$platform])) {
            throw new ExceptionBusiness('机器人平台未注册');
        }
        return $this->drivers[$platform];
    }

    // 返回平台选项供后台配置使用
    public function options(): array
    {
        $event = new BotDriverEvent();
        foreach ($this->drivers as $driver) {
            $event->register($driver);
        }
        App::event()->dispatch($event, 'boot.bot.driver');
        return array_values($event->getMeta());
    }
}
