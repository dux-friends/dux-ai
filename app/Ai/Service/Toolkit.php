<?php

declare(strict_types=1);

namespace App\Ai\Service;

use App\Ai\Service\Toolkit\Service as ToolkitService;
use App\Ai\Support\AiRuntime;
use Core\App;

final class Toolkit
{
    public const DI_KEY = 'ai.toolkit.service';

    private static ?ToolkitService $service = null;

    public static function setService(?ToolkitService $service): void
    {
        self::$service = $service;
        if ($service) {
            App::di()->set(self::DI_KEY, $service);
        }
    }

    public static function reset(): void
    {
        self::$service?->reset();
        self::$service = null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function list(): array
    {
        return self::service()->list();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $code): ?array
    {
        return self::service()->get($code);
    }

    private static function service(): ToolkitService
    {
        if (self::$service) {
            return self::$service;
        }

        $di = App::di();
        if ($di->has(self::DI_KEY)) {
            $resolved = $di->get(self::DI_KEY);
            if ($resolved instanceof ToolkitService) {
                return self::$service = $resolved;
            }
        }

        $instance = new ToolkitService(AiRuntime::instance());
        $di->set(self::DI_KEY, $instance);
        return self::$service = $instance;
    }
}
