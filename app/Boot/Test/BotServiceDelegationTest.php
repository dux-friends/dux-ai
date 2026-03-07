<?php

use App\Boot\Models\BootBot;
use App\Boot\Service\BotFactory;
use App\Boot\Service\BotService;
use App\Boot\Service\Contracts\BotDriverInterface;
use App\Boot\Service\DTO\InboundMessage;
use App\Boot\Service\Message;
use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

it('BotService：回调流程由驱动统一托管', function () {
    $driver = new class implements BotDriverInterface
    {
        public array $calls = [];
        public array|string|null $verifyResponse = null;
        public array|string|null $payloadResponse = null;
        public array $payload = [];
        public mixed $replyResult = ['ok' => 'driver'];

        public function platform(): string
        {
            return 'delegation_fake';
        }

        public function meta(): array
        {
            return ['label' => 'Fake', 'value' => 'delegation_fake'];
        }

        public function send(BootBot $bot, Message $message): array
        {
            $this->calls[] = 'send';
            return ['ok' => true];
        }

        public function verifyCallbackRequest(BootBot $bot, ServerRequestInterface $request): array|string|null
        {
            $this->calls[] = 'verifyCallbackRequest';
            return $this->verifyResponse;
        }

        public function parseWebhookPayload(BootBot $bot, ServerRequestInterface $request): array
        {
            $this->calls[] = 'parseWebhookPayload';
            return $this->payload;
        }

        public function verifyInbound(BootBot $bot, ServerRequestInterface $request, array $payload): bool
        {
            $this->calls[] = 'verifyInbound';
            return true;
        }

        public function resolveWebhookPayloadResponse(
            BootBot $bot,
            array $payload,
            ServerRequestInterface $request
        ): array|string|null {
            $this->calls[] = 'resolveWebhookPayloadResponse';
            return $this->payloadResponse;
        }

        public function parseInbound(BootBot $bot, array $payload, ServerRequestInterface $request): InboundMessage
        {
            $this->calls[] = 'parseInbound';
            return new InboundMessage(
                platform: 'delegation_fake',
                eventId: (string)($payload['event_id'] ?? 'e1'),
                conversationId: (string)($payload['conversation_id'] ?? 'c1'),
                senderId: (string)($payload['sender_id'] ?? 'u1'),
                senderName: 'u1',
                text: (string)($payload['text'] ?? ''),
                timestamp: (int)($payload['timestamp'] ?? time()),
                raw: $payload,
            );
        }

        public function handleWebhookReply(
            BootBot $bot,
            ServerRequestInterface $request,
            InboundMessage $message,
            ?string $replyText,
            bool $ackOnly
        ): array|string {
            $this->calls[] = 'handleWebhookReply';
            return $this->replyResult;
        }
    };

    $driver->payload = [
        'event_id' => 'ev_1',
        'conversation_id' => 'c_1',
        'sender_id' => 'u_1',
        'text' => 'hello',
        'timestamp' => time(),
    ];

    $factory = new BotFactory();
    $factory->register($driver);

    $bot = BootBot::query()->create([
        'name' => 'fake',
        'code' => 'delegation_bot',
        'platform' => 'delegation_fake',
        'enabled' => true,
        'config' => [],
    ]);

    $service = new BotService();
    $ref = new ReflectionClass($service);
    $property = $ref->getProperty('factory');
    $property->setAccessible(true);
    $property->setValue($service, $factory);

    $request = new ServerRequest('POST', 'https://example.com/boot/webhook/delegation_bot');
    $result = $service->handleWebhook('delegation_bot', $request);

    expect($result)->toBe(['ok' => 'driver']);
    expect($driver->calls)->toBe([
        'verifyCallbackRequest',
        'parseWebhookPayload',
        'verifyInbound',
        'resolveWebhookPayloadResponse',
        'parseInbound',
        'handleWebhookReply',
    ]);

    unset($bot);
});
