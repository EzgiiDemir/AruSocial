<?php

namespace App\Services\Ai;

/** What the responder produced and how. */
final class AiResult
{
    public function __construct(
        public readonly ?string $text,
        public readonly ?string $provider,   // 'groq' | 'local' | null
        public readonly string $status,      // AiCompletion::* or 'cached' | 'unavailable'
        public readonly bool $cached = false,
        public readonly bool $usedFallback = false,
        // Set when no AI provider could serve and the caller must answer from
        // internal sources only (grounded, marked source-based, not AI-generated).
        public readonly bool $knowledgeOnly = false,
    ) {}

    public function hasText(): bool
    {
        return $this->text !== null && $this->text !== '';
    }
}
