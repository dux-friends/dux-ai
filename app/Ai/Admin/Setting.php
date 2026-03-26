<?php

declare(strict_types=1);

namespace App\Ai\Admin;

use App\Ai\Service\AiConfig;
use Core\Resources\Attribute\Action;
use Core\Resources\Attribute\Resource;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Resource(app: 'admin', route: '/ai/setting', name: 'ai.setting', actions: false)]
class Setting
{
    #[Action(methods: 'GET', route: '')]
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return send($response, 'ok', AiConfig::get());
    }

    #[Action(methods: 'PUT', route: '')]
    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        AiConfig::set($request->getParsedBody() ?: []);

        return send($response, __('message.edit', 'common'));
    }
}
