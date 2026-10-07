<?php

namespace App\Services\Ai;

/**
 * A model running on ARUCAD's own servers via an OpenAI-compatible gateway
 * (Ollama, vLLM, LM Studio). The preferred provider: when it is configured,
 * ARUVERSE answers with no external LLM dependency.
 */
class LocalAiProvider extends OpenAiCompatibleProvider
{
    public function key(): string
    {
        return 'local';
    }

    public function label(): string
    {
        return 'Yerel AI (Kendi Sunucumuz)';
    }

    public function isSelfHosted(): bool
    {
        return true;
    }
}
