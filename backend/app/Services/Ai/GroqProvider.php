<?php

namespace App\Services\Ai;

/** Groq's hosted OpenAI-compatible API. Optional fallback, not required. */
class GroqProvider extends OpenAiCompatibleProvider
{
    public function key(): string
    {
        return 'groq';
    }

    public function label(): string
    {
        return 'Groq';
    }

    public function isSelfHosted(): bool
    {
        return false;
    }
}
