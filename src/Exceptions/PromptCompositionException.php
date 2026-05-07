<?php

namespace Lyre\AiAgents\Exceptions;

use RuntimeException;

class PromptCompositionException extends RuntimeException
{
    public static function cycleDetected(int $templateId): self
    {
        return new self("Prompt template inheritance cycle detected at template id {$templateId}.");
    }

    public static function depthExceeded(int $depth): self
    {
        return new self("Prompt template inheritance depth {$depth} exceeded the configured maximum.");
    }
}
