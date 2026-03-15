<?php

declare(strict_types=1);

namespace App\Ai\Event;

use Symfony\Contracts\EventDispatcher\Event;

class AgentPromptEvent extends Event
{
    /**
     * @param array<int, string> $instructions
     */
    public function __construct(
        private readonly int $sessionId,
        private array $instructions = [],
    ) {
    }

    public function getSessionId(): int
    {
        return $this->sessionId;
    }

    /**
     * @return array<int, string>
     */
    public function getInstructions(): array
    {
        return $this->instructions;
    }

    public function addInstruction(string $instruction): void
    {
        $instruction = trim($instruction);
        if ($instruction !== '') {
            $this->instructions[] = $instruction;
        }
    }
}
