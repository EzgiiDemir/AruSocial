<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Support\TextFold;
use App\Support\Utf8;
use App\Support\Vector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a crawled page into searchable passages.
 *
 * Two decisions worth knowing before changing anything here.
 *
 * **Why chunks.** The sentence model truncates at 256 tokens. Embedding a
 * whole page therefore represents it by its opening paragraphs, and a fee
 * table or a contact block further down becomes invisible to search — the
 * page would rank for its introduction and nothing else.
 *
 * **Why the title is repeated into every chunk.** A passage lifted out of
 * the middle of a page often does not name its own subject: "Başvurular 1
 * Eylül'de başlar" is about whichever programme the page is about, and
 * the chunk alone cannot say which. Prefixing the title restores the
 * subject the paragraph is relying on.
 */
class KnowledgeIndexer
{
    public function __construct(
        private readonly EmbeddingClient $embeddings,
        private readonly BoilerplateFilter $boilerplate = new BoilerplateFilter,
    ) {}

    /**
     * (Re)build the passages for one document.
     *
     * @return int The number of embedded chunks written; 0 when nothing
     *             could be embedded, which leaves the previous rows in place.
     */
    public function index(KnowledgeDocument $document): int
    {
        // Structured facts first: they need no embedder, so a down
        // classifier must not leave them stale.
        app(KnowledgeFactExtractor::class)->extract($document);

        $texts = $this->chunkTexts($document);
        if ($texts === []) {
            KnowledgeChunk::where('knowledge_document_id', $document->id)->delete();

            return 0;
        }

        $vectors = [];
        $batchSize = max(1, (int) config('knowledge.embeddings.batch', 32));

        foreach (array_chunk($texts, $batchSize) as $batch) {
            $result = $this->embeddings->embed($batch, interactive: false);
            if ($result === null) {
                // The classifier is down or disabled. Leave whatever is
                // already indexed alone: stale vectors still answer
                // questions, and deleting them would silently downgrade
                // every search to keyword matching until the next crawl.
                // A changed official PDF must never keep passages from its
                // obsolete version attached to the new content hash. It can
                // still be retrieved by keywords until embeddings recover.
                if ($document->content_type === 'application/pdf') {
                    KnowledgeChunk::where('knowledge_document_id', $document->id)->delete();
                }

                return 0;
            }
            foreach ($result as $vector) {
                $vectors[] = $vector;
            }
        }

        if (count($vectors) !== count($texts)) {
            if ($document->content_type === 'application/pdf') {
                KnowledgeChunk::where('knowledge_document_id', $document->id)->delete();
            }

            return 0;
        }

        $model = (string) config('knowledge.embeddings.model_label', 'classifier');
        $now = now();

        DB::transaction(function () use ($document, $texts, $vectors, $model, $now): void {
            KnowledgeChunk::where('knowledge_document_id', $document->id)->delete();

            $rows = [];
            foreach ($texts as $i => $text) {
                $rows[] = [
                    'knowledge_document_id' => $document->id,
                    'position' => $i,
                    'text' => $text,
                    'embedding' => Vector::pack($vectors[$i]),
                    'model' => $model,
                    'model_version' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // One insert rather than one per chunk: a 12-chunk page would
            // otherwise be a dozen round trips inside the crawl loop.
            KnowledgeChunk::insert($rows);
        });

        return count($texts);
    }

    /**
     * The passages a document is split into.
     *
     * Paragraph boundaries first, because a paragraph is a unit of
     * meaning and cutting mid-sentence produces embeddings of fragments.
     * A paragraph longer than the budget is split on sentence ends, and
     * only failing that on a hard character count.
     *
     * @return list<string>
     */
    public function chunkTexts(KnowledgeDocument $document): array
    {
        // Crawled HTML is untrusted bytes, not guaranteed UTF-8. Cleaning
        // here means the passage that gets stored — and later handed to
        // the model as grounding — is valid text, not only the copy sent
        // over the wire.
        $content = Utf8::clean((string) $document->content);

        // Site chrome next. Left in, every page's opening passage is the
        // same menu, every embedding of it is nearly identical, and
        // ranking collapses — measured, not theoretical.
        if ($document->content_type !== 'application/pdf') {
            $content = $this->boilerplate->strip($content, $document->language);
        }

        // Keep it. Retrieval needs this same text on every question, and
        // recomputing it there costs more than the ranking it feeds.
        // Folded here too, for the same reason: retrieval matches on the
        // folded form for every document on every question, and folding it
        // there cost more than the ranking it fed.
        $folded = TextFold::fold($content);
        if ($document->content_clean !== $content || $document->content_folded !== $folded) {
            $document->forceFill([
                'content_clean' => $content,
                'content_folded' => $folded,
            ])->saveQuietly();
        }

        $content = trim(preg_replace('/[ \t]+/u', ' ', $content) ?? '');
        if ($content === '') {
            return [];
        }

        $budget = max(200, (int) config('knowledge.embeddings.chunk_chars', 700));
        $maxChunks = max(1, (int) config('knowledge.embeddings.max_chunks_per_document', 12));
        $title = trim(Utf8::clean((string) $document->title));

        $chunks = [];
        $current = '';

        foreach (preg_split('/\n\s*\n+/u', $content) ?: [] as $paragraph) {
            $paragraph = trim((string) $paragraph);
            if ($paragraph === '') {
                continue;
            }

            foreach ($this->splitLong($paragraph, $budget) as $piece) {
                if ($current !== '' && mb_strlen($current) + mb_strlen($piece) + 1 > $budget) {
                    $chunks[] = $current;
                    $current = '';
                }
                $current = $current === '' ? $piece : $current."\n".$piece;
            }
        }

        if (trim($current) !== '') {
            $chunks[] = $current;
        }

        // Bounded per page so one enormous document cannot dominate the
        // corpus, the embedding budget, or the per-query scan.
        $chunks = array_slice($chunks, 0, $maxChunks);

        return array_values(array_map(
            function (string $chunk) use ($title): string {
                if ($title === '') {
                    return $chunk;
                }

                // Pages usually open with their own title, so the first
                // chunk would otherwise carry it twice — wasted room in a
                // 256-token window that the rest of the passage needs.
                return str_starts_with($chunk, $title) ? $chunk : $title."\n".$chunk;
            },
            $chunks,
        ));
    }

    /**
     * Split a paragraph that is longer than the budget.
     *
     * @return list<string>
     */
    private function splitLong(string $paragraph, int $budget): array
    {
        if (mb_strlen($paragraph) <= $budget) {
            return [$paragraph];
        }

        $pieces = [];
        $current = '';

        // Sentence ends, keeping the punctuation with the sentence.
        foreach (preg_split('/(?<=[.!?…])\s+/u', $paragraph) ?: [] as $sentence) {
            $sentence = trim((string) $sentence);
            if ($sentence === '') {
                continue;
            }

            // A single sentence over the budget (a table flattened into
            // one line, typically) is cut on length — there is no
            // meaningful boundary left to use.
            if (mb_strlen($sentence) > $budget) {
                if ($current !== '') {
                    $pieces[] = $current;
                    $current = '';
                }
                // mb_str_split, never str_split: the budget is counted in
                // characters everywhere else here, and splitting on bytes
                // cuts a multi-byte character in half. That produced text
                // Postgres refused outright ("invalid byte sequence for
                // encoding UTF8: 0xc3") — the half-character was written
                // AFTER the content had been cleaned, so no amount of
                // sanitising upstream would have caught it.
                foreach (mb_str_split($sentence, $budget) as $slice) {
                    $pieces[] = trim($slice);
                }

                continue;
            }

            if ($current !== '' && mb_strlen($current) + mb_strlen($sentence) + 1 > $budget) {
                $pieces[] = $current;
                $current = '';
            }
            $current = $current === '' ? $sentence : $current.' '.$sentence;
        }

        if (trim($current) !== '') {
            $pieces[] = $current;
        }

        return array_values(array_filter($pieces, static fn (string $p): bool => trim($p) !== ''));
    }

    /** A short label for logs and command output. */
    public function describe(KnowledgeDocument $document): string
    {
        return Str::limit((string) ($document->title ?: $document->url), 60);
    }
}
