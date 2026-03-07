<?php

declare(strict_types=1);

namespace App\Boot\Event;

use App\Boot\Models\BootBot;
use App\Boot\Service\DTO\InboundMessage;
use Symfony\Contracts\EventDispatcher\Event;

class BotMessageReceivedEvent extends Event
{
    private ?string $replyText = null;
    private bool $ackOnly = false;

    public function __construct(
        private readonly BootBot $bot,
        private readonly InboundMessage $message,
    ) {
    }

    public function getBot(): BootBot
    {
        return $this->bot;
    }

    public function getMessage(): InboundMessage
    {
        return $this->message;
    }

    public function setReplyText(?string $replyText): void
    {
        $replyText = trim((string)$replyText);
        if ($replyText === '') {
            $this->replyText = null;
            return;
        }
        $this->ackOnly = false;
        $this->replyText = $replyText;
    }

    public function getReplyText(): ?string
    {
        return $this->replyText;
    }

    public function setAckOnly(bool $ackOnly = true): void
    {
        $this->ackOnly = $ackOnly;
        if ($ackOnly) {
            $this->replyText = null;
        }
    }

    public function isAckOnly(): bool
    {
        return $this->ackOnly;
    }
}
