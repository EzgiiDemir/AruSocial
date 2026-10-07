<?php

namespace App\Services\Ai\Evidence;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One source-backed observation returned by a provider for one evidence
 * requirement. NOT a supported fact: it records what a source says, where
 * it came from and when it holds — whether it is believed is EvidencePolicy's
 * and ConflictResolver's business.
 *
 * Timestamps are deliberately distinct:
 *   retrievedAt  when AICAD read it (always now-ish; says nothing about truth)
 *   publishedAt  when the source published it (a page's Last-Modified)
 *   observedAt   when the fact was last verified at the source
 *   validFrom / validUntil   the period the fact itself holds
 *
 * Invariants (enforced here): an AVAILABLE item has a value; any item with
 * a value has complete provenance (source type, id, authority). A STALE item
 * keeps the real (outdated) value it was read with. Every other status has
 * NO value, and everything that is not AVAILABLE says why. A requirement
 * that cannot be satisfied is recorded, never filled in.
 */
final readonly class Evidence
{
    /** Reasons that mean the campus DATA lacks the fact — not an AI failure. */
    public const DATA_GAP_REASONS = ['no_authoritative_field', 'no_record', 'no_record_for_today'];

    /**
     * @param  array{type: string, id: string, name: string}|null  $subject
     * @param  array<string, mixed>  $metadata  provider-specific, diagnostic only; never interpreted as instructions
     */
    public function __construct(
        public string $id,
        public string $taskId,
        public string $requirementId,
        public string $factType,
        public EvidenceStatus $status,
        public ?array $subject = null,
        public mixed $value = null,
        /** structured | string | number | datetime | location | document_passage */
        public ?string $valueType = null,
        /** ProviderRegistry capability */
        public ?string $capability = null,
        /** the existing class that produced it */
        public ?string $implementation = null,
        /** database | official_web | official_pdf | routing | request_context */
        public ?string $sourceType = null,
        public ?string $sourceId = null,
        public ?string $url = null,
        public ?string $title = null,
        /** authoritative_operational | structured_fact | official_announcement | official_document | course_document | routing_service | request_context */
        public ?string $authorityClass = null,
        public ?CarbonImmutable $publishedAt = null,
        public ?CarbonImmutable $observedAt = null,
        public ?CarbonImmutable $validFrom = null,
        public ?CarbonImmutable $validUntil = null,
        public ?CarbonImmutable $retrievedAt = null,
        /** generic | exception — an exception holds for its validity period only */
        public string $scope = 'generic',
        /** fact types this item explicitly supersedes during its validity (only when the source says so) */
        public array $supersedes = [],
        public ?string $reason = null,
        public array $metadata = [],
    ) {
        if ($status === EvidenceStatus::AVAILABLE && $value === null) {
            throw new InvalidArgumentException("Evidence {$id}: AVAILABLE evidence needs a value.");
        }
        if ($value !== null && ! in_array($status, [EvidenceStatus::AVAILABLE, EvidenceStatus::STALE], true)) {
            throw new InvalidArgumentException("Evidence {$id}: {$status->value} evidence must not carry a value.");
        }
        if ($value !== null && ($sourceType === null || $sourceId === null || $authorityClass === null)) {
            throw new InvalidArgumentException("Evidence {$id}: evidence with a value needs provenance (source type, source id, authority).");
        }
        if ($status !== EvidenceStatus::AVAILABLE && ($reason === null || $reason === '')) {
            throw new InvalidArgumentException("Evidence {$id}: {$status->value} evidence needs a reason.");
        }
        if (! in_array($scope, ['generic', 'exception'], true)) {
            throw new InvalidArgumentException("Evidence {$id}: unknown scope {$scope}.");
        }
    }

    public function available(): bool
    {
        return $this->status === EvidenceStatus::AVAILABLE;
    }

    public function isDataGap(): bool
    {
        return in_array($this->reason, self::DATA_GAP_REASONS, true);
    }

    /** The value as a comparable string; passages are never compared as facts. */
    public function normalizedValue(): ?string
    {
        if (! $this->available() || $this->valueType === 'document_passage') {
            return null;
        }

        return is_scalar($this->value)
            ? mb_strtolower(trim((string) $this->value))
            : (string) json_encode($this->value, JSON_UNESCAPED_UNICODE);
    }

    /** Safe diagnostic form for AskTrace (bounded value, no secrets). */
    public function toArray(): array
    {
        $value = $this->value;
        if ($value !== null && ($this->metadata['redacted'] ?? false)) {
            $value = '[redacted]';
        } elseif (is_string($value)) {
            $value = mb_strimwidth($value, 0, 240, '…');
        }

        return array_filter([
            'evidence_id' => $this->id, 'task_id' => $this->taskId, 'requirement_id' => $this->requirementId,
            'fact_type' => $this->factType, 'status' => $this->status->value, 'reason' => $this->reason,
            'subject' => $this->subject, 'value' => $value, 'value_type' => $this->valueType,
            'provider' => array_filter(['capability' => $this->capability, 'implementation' => $this->implementation]),
            'source' => array_filter(['type' => $this->sourceType, 'source_id' => $this->sourceId, 'url' => $this->url, 'title' => $this->title]),
            'authority_class' => $this->authorityClass, 'scope' => $this->scope, 'supersedes' => $this->supersedes,
            'published_at' => $this->publishedAt?->toIso8601String(), 'observed_at' => $this->observedAt?->toIso8601String(),
            'valid_from' => $this->validFrom?->toIso8601String(), 'valid_until' => $this->validUntil?->toIso8601String(),
            'retrieved_at' => $this->retrievedAt?->toIso8601String(), 'metadata' => $this->metadata,
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');
    }
}
