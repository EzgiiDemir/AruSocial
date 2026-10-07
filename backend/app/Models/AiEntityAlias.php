<?php

namespace App\Models;

use App\Services\Ai\RetrievalVersion;
use App\Support\RequestMemo;
use App\Support\TableStamp;
use App\Support\TextFold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * An alternative name for a canonical campus entity ("gym" → Sports Center).
 *
 * Read on every question, so the active set is cached as one small map and
 * the cache is dropped on every write.
 */
class AiEntityAlias extends Model
{
    public const TYPE_PLACE = 'place';

    public const TYPE_SERVICE = 'service';

    public const TYPE_CLUB = 'club';

    public const TYPE_SPORT = 'sport';

    public const TYPE_FOOD_VENUE = 'food_venue';

    public const TYPE_STAFF = 'staff';

    /**
     * A programme has no row of its own: its id is the folded subject of its
     * knowledge_facts (see ProgrammeCatalog), and its aliases are its other
     * official names (English, Russian).
     */
    public const TYPE_PROGRAMME = 'programme';

    /** Entity type → the model that owns the canonical row. */
    public const TYPES = [
        self::TYPE_PLACE => Place::class,
        self::TYPE_SERVICE => ServiceItem::class,
        self::TYPE_CLUB => Club::class,
        self::TYPE_SPORT => Sport::class,
        self::TYPE_FOOD_VENUE => FoodVenue::class,
        self::TYPE_STAFF => StaffProfile::class,
        self::TYPE_PROGRAMME => KnowledgeFact::class,
    ];

    private const CACHE_KEY = 'ai_entity_aliases:index';

    protected $fillable = ['entity_type', 'entity_id', 'alias', 'locale', 'active', 'created_by'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * Active aliases for one entity, folded.
     *
     * @return list<string>
     */
    public static function for(string $type, string $id): array
    {
        return self::index()[$type][$id] ?? [];
    }

    /**
     * type => id => folded aliases, for every active alias.
     *
     * Read once per request, from a cache whose key is the table's own
     * stamp (row count + newest updated_at). A bulk delete or update that
     * fires no model event still changes the stamp, so a stale alias can no
     * longer outlive its row for the cache's lifetime.
     *
     * @return array<string, array<string, list<string>>>
     */
    public static function index(): array
    {
        return app(RequestMemo::class)->remember(self::CACHE_KEY, function (): array {
            // Safe on a database that has not run the migration yet: the
            // resolver must keep working with no aliases at all.
            if (! Schema::hasTable('ai_entity_aliases')) {
                return [];
            }

            return Cache::remember(self::CACHE_KEY.':'.sha1(TableStamp::of('ai_entity_aliases').'|'.RetrievalVersion::counter()), now()->addMinutes(10), function (): array {
                $out = [];
                foreach (self::query()->where('active', true)->get(['entity_type', 'entity_id', 'normalized_alias']) as $row) {
                    $out[$row->entity_type][$row->entity_id][] = (string) $row->normalized_alias;
                }

                return $out;
            });
        });
    }

    /** The canonical row this alias points at, or null if it was deleted. */
    public function entity(): ?Model
    {
        if ($this->entity_type === self::TYPE_PROGRAMME) {
            return KnowledgeFact::query()->where('subject_folded', $this->entity_id)->first();
        }
        $class = self::TYPES[$this->entity_type] ?? null;

        return $class === null ? null : $class::query()->find($this->entity_id);
    }

    protected static function booted(): void
    {
        static::saving(function (self $alias): void {
            $alias->alias = trim((string) $alias->alias);
            $alias->normalized_alias = trim(preg_replace('/\s+/u', ' ', TextFold::fold($alias->alias)) ?? '');
        });

        // An operator adding "gym" expects the next question to use it —
        // including later in this same request (a test, a seeder).
        $forget = static function (): void {
            app(RequestMemo::class)->forget(self::CACHE_KEY);
            TableStamp::touched('ai_entity_aliases');
        };
        static::saved($forget);
        static::deleted($forget);
    }
}
