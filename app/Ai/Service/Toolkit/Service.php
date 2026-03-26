<?php

declare(strict_types=1);

namespace App\Ai\Service\Toolkit;

use App\Ai\Event\AiToolkitEvent;
use App\Ai\Service\Capability;
use App\Ai\Support\AiRuntimeInterface;

final class Service
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $registry = [];

    private bool $booted = false;

    public function __construct(private readonly AiRuntimeInterface $runtime)
    {
    }

    public function reset(): void
    {
        $this->registry = [];
        $this->booted = false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $this->boot();
        return array_values($this->registry);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $code): ?array
    {
        $this->boot();
        return $this->registry[strtolower(trim($code))] ?? null;
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $event = new AiToolkitEvent();
        $this->runtime->event()->dispatch($event, 'ai.toolkit');

        $registry = [];
        foreach ($event->getRegistry() as $code => $meta) {
            if (!is_array($meta)) {
                continue;
            }
            $registry[$code] = $this->normalizeMeta($meta);
        }

        $this->registry = $registry;
        $this->booted = true;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function normalizeMeta(array $meta): array
    {
        $items = [];
        $rawItems = is_array($meta['items'] ?? null) ? ($meta['items'] ?? []) : [];
        foreach ($rawItems as $item) {
            $code = '';
            $localMeta = [];
            if (is_string($item)) {
                $code = trim($item);
            } elseif (is_array($item)) {
                $localMeta = $item;
                $code = trim((string)($item['code'] ?? ''));
            }
            if ($code === '') {
                continue;
            }

            $capability = Capability::get($code) ?: [];
            $normalized = array_replace_recursive([
                'code' => $code,
                'label' => $capability['label'] ?? $capability['name'] ?? $code,
                'description' => $capability['description'] ?? '',
                'icon' => $capability['icon'] ?? 'i-tabler:puzzle',
                'color' => $capability['color'] ?? 'primary',
                'settings' => is_array($capability['settings'] ?? null) ? ($capability['settings'] ?? []) : [],
                'defaults' => is_array($capability['defaults'] ?? null) ? ($capability['defaults'] ?? []) : [],
            ], $localMeta, [
                'code' => $code,
            ]);
            if (array_key_exists('inherit_settings', $localMeta) && !$localMeta['inherit_settings']) {
                $normalized['settings'] = is_array($localMeta['settings'] ?? null) ? ($localMeta['settings'] ?? []) : [];
            }
            if (array_key_exists('inherit_defaults', $localMeta) && !$localMeta['inherit_defaults']) {
                $normalized['defaults'] = is_array($localMeta['defaults'] ?? null) ? ($localMeta['defaults'] ?? []) : [];
            }
            unset($normalized['inherit_settings'], $normalized['inherit_defaults']);
            $items[] = $normalized;
        }

        return array_replace_recursive([
            'code' => trim((string)($meta['code'] ?? '')),
            'label' => trim((string)($meta['label'] ?? $meta['code'] ?? '')),
            'description' => trim((string)($meta['description'] ?? '')),
            'icon' => 'i-tabler:tool',
            'color' => 'primary',
            'style' => [],
            'agent_selectable' => false,
            'defaults' => [],
            'settings' => [],
            'items' => [],
        ], $meta, [
            'items' => $items,
        ]);
    }
}
