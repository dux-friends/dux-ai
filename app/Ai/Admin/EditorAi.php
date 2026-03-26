<?php

declare(strict_types=1);

namespace App\Ai\Admin;

use App\Ai\Service\Agent\HttpRequest;
use App\Ai\Service\Agent\OpenAiHttp;
use App\Ai\Service\Agent\Sse;
use App\Ai\Service\Agent\SseGeneratorStream;
use App\Ai\Service\EditorAiService;
use Core\Handlers\ExceptionBusiness;
use Core\Route\Attribute\Route;
use Core\Route\Attribute\RouteGroup;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[RouteGroup(app: 'admin', route: '/editorAi', name: 'ai.editorAi')]
class EditorAi
{
    #[Route(methods: 'POST', route: '')]
    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = HttpRequest::jsonBody($request);
        $prompt = trim((string)($body['prompt'] ?? ''));
        if ($prompt === '') {
            throw new ExceptionBusiness('prompt 不能为空');
        }

        Sse::prepareStreaming();

        try {
            $stream = SseGeneratorStream::fromGenerator(
                (new EditorAiService())->generate($prompt)
            );
        } catch (\Throwable $e) {
            return OpenAiHttp::sseErrorResponse($response, 500, $e->getMessage());
        }

        return OpenAiHttp::withSseHeaders($response)
            ->withHeader('Content-Encoding', 'none')
            ->withBody($stream);
    }
}
