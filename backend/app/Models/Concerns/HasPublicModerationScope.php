<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * A database-level publication firebreak.  Public query paths use the model
 * query builder, therefore a controller cannot accidentally expose a row
 * merely by forgetting an extra where clause.
 */
trait HasPublicModerationScope
{
    public static function bootHasPublicModerationScope(): void
    {
        static::addGlobalScope('approved-content', function (Builder $query): void {
            $query->where($query->getModel()->getTable().'.moderation_status', 'approved');
        });
    }

    public function scopeIncludingUnmoderated(Builder $query): Builder
    {
        return $query->withoutGlobalScope('approved-content');
    }
}
