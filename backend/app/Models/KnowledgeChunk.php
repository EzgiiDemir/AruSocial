<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One passage of a crawled page, with its embedding.
 *
 * Derived from `knowledge_documents` and rebuildable at any time — see
 * the migration for why pages are split rather than embedded whole.
 */
class KnowledgeChunk extends Model
{
    protected $fillable = [
        'knowledge_document_id', 'position', 'text', 'embedding', 'model', 'model_version',
    ];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }
}
