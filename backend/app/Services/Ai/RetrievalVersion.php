<?php

namespace App\Services\Ai;

use App\Models\AcademicYear;
use App\Models\AiEntityAlias;
use App\Models\AiQueryConcept;
use App\Models\CareerOpportunity;
use App\Models\Club;
use App\Models\Consultation;
use App\Models\DirectoryEntry;
use App\Models\Event;
use App\Models\FoodDailyMenu;
use App\Models\FoodVenue;
use App\Models\KnowledgeFact;
use App\Models\Place;
use App\Models\ServiceItem;
use App\Models\ShuttleRoute;
use App\Models\Sport;
use App\Models\StaffProfile;
use App\Support\RequestMemo;
use App\Support\SchemaColumnCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A stamp that changes whenever anything an answer was built from changes,
 * so the shared answer cache never serves an answer older than its data.
 *
 * Most campus tables have no `updated_at` (events, places, clubs, menus…),
 * so a timestamp alone cannot see an edit. Instead every model the
 * assistant reads bumps a counter on save and delete; row counts and, where
 * present, MAX(updated_at) cover writes that bypass the models (the
 * indexer's bulk passage insert). The date is part of the stamp, so no
 * cached answer about "today" survives midnight.
 */
final class RetrievalVersion
{
    private const COUNTER_KEY = 'ai:retrieval-version';

    /** Models whose rows reach the prompt or decide retrieval. */
    public const MODELS = [
        AiEntityAlias::class, AiQueryConcept::class, KnowledgeFact::class, Event::class, Place::class, ServiceItem::class, Club::class, Sport::class,
        FoodVenue::class, FoodDailyMenu::class, ShuttleRoute::class, StaffProfile::class,
        DirectoryEntry::class, AcademicYear::class, Consultation::class, CareerOpportunity::class,
    ];

    /** Tables stamped by count (+ MAX(updated_at) when the column exists). */
    private const TABLES = [
        'ai_entity_aliases', 'ai_query_concepts', 'knowledge_facts', 'knowledge_chunks', 'events', 'places', 'services', 'clubs', 'sports',
        'food_venues', 'food_daily_menus', 'shuttle_routes', 'staff_profiles', 'directory_entries',
        'academic_years', 'consultations', 'career_opportunities',
    ];

    /** Register the save/delete listeners. Called once from AppServiceProvider. */
    public static function listen(): void
    {
        foreach (self::MODELS as $model) {
            $model::saved(static fn () => self::bump());
            $model::deleted(static fn () => self::bump());
        }
    }

    public static function bump(): void
    {
        if (! Cache::add(self::COUNTER_KEY, 1)) {
            Cache::increment(self::COUNTER_KEY);
        }
        // Results resolved earlier in this request are stale too.
        $memo = app(RequestMemo::class);
        foreach (['entities:', 'concepts:', 'table-stamp:', 'ai_entity_aliases:', 'ai_query_concepts:', 'knowledge_facts:'] as $prefix) {
            $memo->forgetPrefix($prefix);
        }
    }

    /** The model-write counter, for cache keys that must also see same-second edits. */
    public static function counter(): int
    {
        return (int) Cache::get(self::COUNTER_KEY, 0);
    }

    public static function stamp(): string
    {
        $parts = [now()->toDateString(), 'v'.(int) Cache::get(self::COUNTER_KEY, 0)];
        foreach (self::TABLES as $table) {
            // A table not migrated yet contributes nothing rather than failing the answer.
            if (! app(RequestMemo::class)->remember('has-table:'.$table, fn () => Schema::hasTable($table))) {
                continue;
            }
            $part = $table.'#'.DB::table($table)->count();
            if (SchemaColumnCache::hasColumn($table, 'updated_at')) {
                $part .= '@'.DB::table($table)->max('updated_at');
            }
            $parts[] = $part;
        }

        return sha1(implode('|', $parts));
    }
}
