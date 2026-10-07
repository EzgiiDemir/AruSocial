<?php

namespace App\Services\Ai\Planning;

/** A span of the user's message that refers to something. Not yet an entity: detection never picks the canonical row. */
final readonly class Mention
{
    public function __construct(
        public string $surface,
        /** entity | conversation_reference | conversation_reference:<entity type> */
        public string $typeHint,
        public int $start,
        public int $end,
    ) {}

    public function toArray(): array
    {
        return ['surface' => $this->surface, 'type_hint' => $this->typeHint, 'span' => [$this->start, $this->end]];
    }
}
