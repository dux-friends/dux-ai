<?php

declare(strict_types=1);

namespace App\Boot\Event;

use App\Boot\Service\Contracts\BotDriverInterface;
use Symfony\Contracts\EventDispatcher\Event;

class BotDriverEvent extends Event
{
    /** @var array<string, BotDriverInterface> */
    private array $drivers = [];

    /** @var array<string, array<string, mixed>> */
    private array $meta = [];

    public function register(BotDriverInterface $driver, array $meta = []): void
    {
        $platform = strtolower(trim($driver->platform()));
        if ($platform === '') {
            return;
        }
        $this->drivers[$platform] = $driver;
        $this->meta[$platform] = array_merge($driver->meta(), $meta, [
            'value' => $platform,
        ]);
    }

    /** @return array<string, BotDriverInterface> */
    public function getDrivers(): array
    {
        return $this->drivers;
    }

    /** @return array<string, array<string, mixed>> */
    public function getMeta(): array
    {
        return $this->meta;
    }
}
