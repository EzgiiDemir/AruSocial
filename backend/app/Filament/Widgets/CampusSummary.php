<?php

namespace App\Filament\Widgets;

use App\Models\Club;
use App\Models\Event;
use App\Models\Place;
use App\Models\User;
use App\Services\GranularPermissions;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What is live on campus right now — four numbers, not fourteen.
 *
 * Deliberately small. A dashboard that shows a count of every table teaches
 * people to ignore all of them, and none of those counts is a question
 * anybody was asking. These four are chosen because each one can be *wrong*
 * in a way staff would want to catch: an event that should be on the
 * calendar and is not, content sitting deleted that nobody restored.
 *
 * Colour is used only where it means something. A plain number is grey.
 */
class CampusSummary extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    public function getHeading(): ?string
    {
        return __('panel.dashboard.on_campus');
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && GranularPermissions::allows($user, 'stats.view');
    }

    protected function getStats(): array
    {
        $listed = Event::query()->publiclyListed()->count();

        // Drafts and pending review are the ones that surprise people:
        // somebody wrote the event, it never went live, and nobody noticed
        // until a student asked where it was.
        $notLive = Event::query()
            ->whereIn('workflow_status', ['draft', 'pending'])
            ->count();

        $deleted = Place::onlyTrashed()->count()
            + Event::onlyTrashed()->count()
            + Club::onlyTrashed()->count();

        return [
            Stat::make('Events on the calendar', (string) $listed)
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('gray'),

            Stat::make('Events not live', (string) $notLive)
                ->description($notLive > 0 ? 'Draft or awaiting review' : 'Nothing waiting')
                ->descriptionIcon($notLive > 0 ? 'heroicon-m-pencil-square' : 'heroicon-m-check-circle')
                ->color($notLive > 0 ? 'warning' : 'gray'),

            Stat::make('Places on the map', (string) Place::query()->count())
                ->descriptionIcon('heroicon-m-map-pin')
                ->color('gray'),

            // Deleted content is recoverable but invisible. Surfacing the
            // count is what makes a mis-click findable by somebody other
            // than the person who made it.
            Stat::make('Deleted, recoverable', (string) $deleted)
                ->description($deleted > 0 ? 'Filter by Trashed to restore' : 'Nothing deleted')
                ->descriptionIcon('heroicon-m-trash')
                ->color($deleted > 0 ? 'warning' : 'gray'),
        ];
    }
}
