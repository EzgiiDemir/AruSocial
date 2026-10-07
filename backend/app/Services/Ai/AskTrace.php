<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

/**
 * What happened to one Ask question, stage by stage.
 *
 * Bound as a scoped singleton, so every service touched by one request
 * writes to the same trace and the next request starts clean. Production
 * requests record a cheap summary per stage; detail that costs queries (the
 * full candidate list with titles, every database row selected) is only
 * gathered when a diagnostic run turns `verbose` on.
 *
 * Nothing here is persisted. The system prompt itself is never recorded —
 * only its size — and personal fields are redacted by key, so a trace can
 * be shown to staff without showing them a student's data.
 */
final class AskTrace
{
    /** Keys whose values are never kept, at any depth. */
    private const REDACTED_KEYS = [
        'personal', 'personal_lines', 'email', 'password', 'token', 'api_key', 'secret', 'system_prompt',
    ];

    public readonly string $id;

    private bool $verbose = false;

    private float $startedAt;

    /** @var list<array{stage: string, ms: float, data: array<string, mixed>}> */
    private array $stages = [];

    public function __construct()
    {
        $this->id = (string) Str::uuid();
        $this->startedAt = microtime(true);
    }

    public function setVerbose(bool $verbose = true): void
    {
        $this->verbose = $verbose;
    }

    public function isVerbose(): bool
    {
        return $this->verbose;
    }

    /** @param array<string, mixed> $data */
    public function record(string $stage, array $data = []): void
    {
        $this->stages[] = [
            'stage' => $stage,
            'ms' => round((microtime(true) - $this->startedAt) * 1000, 1),
            'data' => self::redact($data),
        ];
    }

    /**
     * Record a stage only on verbose runs; `$data` is not even evaluated
     * otherwise, so production pays nothing for diagnostics it never shows.
     *
     * @param  callable(): array<string, mixed>  $data
     */
    public function detail(string $stage, callable $data): void
    {
        if ($this->verbose) {
            $this->record($stage, $data());
        }
    }

    /** @return array<string, mixed>|null The latest data recorded for a stage. */
    public function get(string $stage): ?array
    {
        for ($i = count($this->stages) - 1; $i >= 0; $i--) {
            if ($this->stages[$i]['stage'] === $stage) {
                return $this->stages[$i]['data'];
            }
        }

        return null;
    }

    /** @return list<array{stage: string, ms: float, data: array<string, mixed>}> */
    public function stages(): array
    {
        return $this->stages;
    }

    /** Stage names with their offsets — safe for an ordinary log line. */
    public function summary(): array
    {
        return array_map(fn (array $s) => $s['stage'].'@'.$s['ms'], $this->stages);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACTED_KEYS, true)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}
