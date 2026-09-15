<?php

namespace App\Filament\Concerns;

use Illuminate\Support\Str;

/**
 * Gives a new row the same kind of id the JSON API would have given it.
 *
 * The campus tables use non-incrementing string keys minted as
 * `place-<uuid>`, `club-<uuid>` and so on (`ApiResponds::newId`). The
 * database will not generate one, so a Create page that does not mint an id
 * fails on insert — and asking a member of staff to invent a primary key is
 * not a form anyone should be shown.
 *
 * Same prefix as the API on purpose: a row's origin should not be readable
 * from its id, because then nothing downstream starts depending on it.
 */
trait MintsPrefixedId
{
    /**
     * The prefix for rows created on this page, e.g. `place`.
     */
    abstract protected function idPrefix(): string;

    /**
     * Exposed separately from the hook so a page that needs its own
     * `mutateFormDataBeforeCreate` — the Trainer event page stamps
     * ownership there — can still mint an id without reimplementing it.
     */
    protected function mintId(array $data): array
    {
        $data['id'] ??= $this->idPrefix().'-'.Str::uuid();

        return $data;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->mintId($data);
    }
}
