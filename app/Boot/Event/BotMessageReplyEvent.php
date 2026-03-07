<?php

declare(strict_types=1);

namespace App\Boot\Event;

use Symfony\Contracts\EventDispatcher\Event;

class BotMessageReplyEvent extends Event
{
    public function __construct(
        private readonly string $botCode,
        private readonly string $replyText,
    ) {
    }

    public function getBotCode(): string
    {
        return $this->botCode;
    }

    public function getReplyText(): string
    {
        return $this->replyText;
    }
}

