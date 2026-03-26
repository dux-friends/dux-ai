<?php

declare(strict_types=1);

namespace App\Ai\Event;

use Symfony\Contracts\EventDispatcher\Event;

class AiToolkitEvent extends Event
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $registry = [];

    /**
     * @param array<string, mixed> $meta
     */
    public function register(array $meta): void
    {
        $code = strtolower(trim((string)($meta['code'] ?? $meta['value'] ?? '')));
        if ($code === '') {
            return;
        }

        $this->registry[$code] = array_replace_recursive([
            'code' => $code,
            'label' => $code,
            'description' => '',
            'icon' => 'i-tabler:tool',
            'color' => 'primary',
            'style' => [],
            'agent_selectable' => false,
            'defaults' => [],
            'settings' => [],
            'items' => [],
        ], $this->registry[$code] ?? [], $meta, [
            'code' => $code,
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getRegistry(): array
    {
        return $this->registry;
    }
}
