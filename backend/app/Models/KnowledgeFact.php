<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A stable fact extracted from a reliable page structure, with provenance
 * (source URL, the exact passage, when it was verified). Written only by
 * KnowledgeFactExtractor; never by a model.
 */
class KnowledgeFact extends Model
{
    public const SUBJECT_PROGRAMME = 'programme';

    public const LANGUAGE = 'language_of_instruction';

    public const DURATION = 'duration';

    protected $fillable = [
        'knowledge_document_id', 'subject_type', 'subject', 'subject_folded', 'attribute', 'value',
        'source_url', 'source_passage', 'verified_at',
    ];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }
}
