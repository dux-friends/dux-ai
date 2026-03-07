<?php

declare(strict_types=1);

namespace App\Boot\Admin;

use App\Boot\Models\BootMessageLog;
use Core\Resources\Action\Resources;
use Core\Resources\Attribute\Resource;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ServerRequestInterface;

#[Resource(app: 'admin', route: '/boot/log', name: 'boot.log', actions: ['list', 'show', 'delete'])]
class Log extends Resources
{
    protected string $model = BootMessageLog::class;

    public function queryMany(Builder $query, ServerRequestInterface $request, array $args): void
    {
        $params = $request->getQueryParams();
        if ($params['platform']) {
            $query->where('platform', (string)$params['platform']);
        }
        if ($params['direction']) {
            $query->where('direction', (string)$params['direction']);
        }
        if ($params['status']) {
            $query->where('status', (string)$params['status']);
        }
        if ($params['keyword']) {
            $keyword = (string)$params['keyword'];
            $query->where(function (Builder $builder) use ($keyword) {
                $builder->where('content', 'like', "%{$keyword}%")
                    ->orWhere('event_id', 'like', "%{$keyword}%")
                    ->orWhere('sender_name', 'like', "%{$keyword}%");
            });
        }
        $query->orderByDesc('id');
    }

    public function transform(object $item): array
    {
        /** @var BootMessageLog $item */
        return $item->transform();
    }
}

