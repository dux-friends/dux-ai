<?php

declare(strict_types=1);

namespace App\Boot\Admin;

use App\Boot\Models\BootBot;
use App\Boot\Service\BotService;
use Core\Resources\Action\Resources;
use Core\Resources\Attribute\Action;
use Core\Resources\Attribute\Resource;
use Core\Validator\Data;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Resource(app: 'admin', route: '/boot/bot', name: 'boot.bot')]
class Bot extends Resources
{
    protected string $model = BootBot::class;

    public function queryMany(Builder $query, ServerRequestInterface $request, array $args): void
    {
        $params = $request->getQueryParams();
        if ($params['keyword']) {
            $keyword = (string)$params['keyword'];
            $query->where(function (Builder $builder) use ($keyword) {
                $builder->where('name', 'like', "%{$keyword}%")
                    ->orWhere('code', 'like', "%{$keyword}%");
            });
        }
        if ($params['platform']) {
            $query->where('platform', (string)$params['platform']);
        }
        if ($params['enabled'] !== null && $params['enabled'] !== '') {
            $query->where('enabled', (int)!!$params['enabled']);
        }
        $query->orderByDesc('id');
    }

    public function transform(object $item): array
    {
        /** @var BootBot $item */
        return $item->transform();
    }

    public function validator(array $data, ServerRequestInterface $request, array $args): array
    {
        return [
            'name' => ['required', '请输入实例名称'],
            'code' => ['required', '请输入实例编码'],
            'platform' => ['required', '请选择平台'],
        ];
    }

    public function format(Data $data, ServerRequestInterface $request, array $args): array
    {
        return [
            'name' => trim((string)$data->name),
            'code' => trim((string)$data->code),
            'platform' => trim((string)$data->platform),
            'enabled' => (bool)$data->enabled,
            'config' => is_array($data->config) ? $data->config : [],
            'timeout_ms' => 10000,
            'remark' => trim((string)($data->remark ?? '')) ?: null,
        ];
    }

    #[Action(methods: 'GET', route: '/platforms')]
    public function platforms(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return send($response, 'ok', (new BotService())->platformOptions());
    }

    #[Action(methods: 'GET', route: '/options')]
    public function options(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $params = $request->getQueryParams() + [
            'keyword' => null,
            'enabled' => 1,
        ];
        $platformOptions = (new BotService())->platformOptions();
        $platformMap = [];
        foreach ($platformOptions as $option) {
            if (!is_array($option)) {
                continue;
            }
            $key = strtolower(trim((string)($option['value'] ?? '')));
            if ($key === '') {
                continue;
            }
            $platformMap[$key] = $option;
        }

        $query = BootBot::query()->orderByDesc('id');
        if ($params['enabled'] !== null && $params['enabled'] !== '') {
            $query->where('enabled', (int)!!$params['enabled']);
        }
        if ($params['keyword']) {
            $keyword = (string)$params['keyword'];
            $query->where(function (Builder $builder) use ($keyword) {
                $builder->where('name', 'like', "%{$keyword}%")
                    ->orWhere('code', 'like', "%{$keyword}%");
            });
        }

        $items = $query->get()->map(static function (BootBot $bot) use ($platformMap) {
            $platform = (string)$bot->platform;
            $platformMeta = is_array($platformMap[$platform] ?? null) ? ($platformMap[$platform] ?? []) : [];
            $platformName = (string)($platformMeta['label'] ?? $platform);
            $style = is_array($platformMeta['style'] ?? null) ? ($platformMeta['style'] ?? []) : [];

            return [
                'id' => (string)$bot->code,
                'label' => (string)$bot->name,
                'value' => (string)$bot->code,
                'desc' => sprintf('%s · %s', (string)$bot->code, $platformName),
                'platform' => $platform,
                'platform_name' => $platformName,
                'icon' => (string)($platformMeta['icon'] ?? 'i-tabler:robot'),
                'color' => (string)($platformMeta['color'] ?? 'primary'),
                'style' => [
                    'iconClass' => (string)($style['iconClass'] ?? 'text-primary'),
                    'iconBgClass' => (string)($style['iconBgClass'] ?? 'bg-primary/10'),
                ],
            ];
        });

        return send($response, 'ok', $items);
    }
}
