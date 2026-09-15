<?php

namespace App\Filament\Trainer\Widgets;

use App\Filament\Trainer\Resources\Events\EventResource;
use App\Models\Event;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * A department head's own numbers.
 *
 * Same widget type, same colour rules and same place on the page as the
 * Admin panel's summary — the difference is the scope, not the design.
 *
 * "Awaiting approval" is first because it is the only one that is somebody
 * else's turn: the trainer has done their part and is waiting on an admin.
 */
class DepartmentSummary extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    public function getHeading(): ?string
    {
        return __('panel.dashboard.my_department');
    }

    public static function canView(): bool
    {
        return EventResource::canViewAny();
    }

    protected function getStats(): array
    {
        $staff = EventResource::staffProfile();

        if ($staff === null) {
            return [];
        }

        $mine = fn () => Event::query()->where('responsible_staff_id', $staff->id);

        $pending = (clone $mine())->where('workflow_status', 'pending')->count();
        $live = (clone $mine())->publiclyListed()->count();
        $upcoming = (clone $mine())
            ->publiclyListed()
            ->whereDate('event_date', '>=', today())
            ->count();

        return [
            Stat::make('Awaiting approval', (string) $pending)
                ->description($pending > 0 ? 'With an administrator' : 'Nothing waiting')
                ->descriptionIcon($pending > 0 ? 'heroicon-m-clock' : 'heroicon-m-check-circle')
                ->color($pending > 0 ? 'warning' : 'gray'),

            Stat::make('On the calendar', (string) $live)
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('gray'),

            Stat::make('Still to come', (string) $upcoming)
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('gray'),
        ];
    }
}
