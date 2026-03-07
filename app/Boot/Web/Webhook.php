<?php

declare(strict_types=1);

namespace App\Boot\Web;

use App\Boot\Service\BotService;
use Core\App;
use Core\Route\Attribute\Route;
use Core\Route\Attribute\RouteGroup;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

#[RouteGroup(app: 'web', route: '/boot', name: 'boot')]
class Webhook
{
    #[Route(methods: ['GET', 'POST'], route: '/webhook/{code}', auth: false)]
    public function receive(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $code = trim((string)$args['code']);
        $ip = (string)($request->getServerParams()['REMOTE_ADDR'] ?? '');
        App::log('boot')->info('boot.webhook.request', [
            'code' => $code,
            'ip' => $ip,
            'query' => $request->getQueryParams(),
        ]);

        try {
            $data = (new BotService())->handleWebhook($code, $request);
            App::log('boot')->info('boot.webhook.received', [
                'code' => $code,
                'ip' => $ip,
                'result' => $data,
            ]);
        } catch (Throwable $e) {
            App::log('boot')->error('boot.webhook.failed', [
                'code' => $code,
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
        if (is_string($data)) {
            $response->getBody()->write($data);
            return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $response->getBody()->write((string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
