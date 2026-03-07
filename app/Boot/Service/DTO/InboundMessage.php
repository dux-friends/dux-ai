<?php

declare(strict_types=1);

namespace App\Boot\Service\DTO;

final class InboundMessage
{
    public function __construct(
        public string $platform,
        public string $eventId,
        public string $conversationId,
        public string $senderId,
        public string $senderName,
        public string $text,
        public int $timestamp,
        public array $raw = [],
    ) {
    }
}

