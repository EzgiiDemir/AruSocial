<?php

namespace App\Filament\Widgets;

use App\Models\ModerationAppeal;
use App\Models\ModerationCase;
use App\Models\ModerationReport;
use App\Models\User;
use App\Services\GranularPermissions;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What is waiting for a person, and how long it has been waiting.
 *
 * First on the dashboard because it is the only thing on it that is
 * time-sensitive. A student whose post was held is waiting; a student who
 * appealed a removal is waiting and has been told they will hear back.
 *
 * Each stat is coloured by whether the promise is still being kept, not by
 * whether the number is large. A queue of forty items reviewed the same day
 * is fine; one item sitting for four days is not, and a dashboard that
 * shows both as "40" and "1" tells you the wrong one is urgent.
 */
class ModerationAlerts extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public function getHeading(): ?string
    {
        return __('panel.dashboard.needs_attention');
    }

    /**
     * The published commitment. Anything older than this is late, and the
     * widget says so rather than leaving someone to work it out.
     */
    private const PROMISE_HOURS = 24;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && GranularPermissions::allows($user, 'moderation.moderate');
    }

    protected function getStats(): array
    {
        $cutoff = Carbon::now()->subHours(self::PROMISE_HOURS);

        // Open *and* reviewing: a case someone started looking at and
        // did not finish is still a student waiting, and counting only
        // untouched ones makes a stalled queue look empty.
        $pendingCases = ModerationCase::query()->whereIn('status', [
            ModerationCase::STATUS_OPEN,
            ModerationCase::STATUS_REVIEWING,
        ]);
        $cases = (clone $pendingCases)->count();
        $lateCases = (clone $pendingCases)->where('created_at', '<', $cutoff)->count();

        $openAppeals = ModerationAppeal::query()->whereIn('status', [
            ModerationAppeal::STATUS_OPEN,
            ModerationAppeal::STATUS_REVIEWING,
        ]);
        $appeals = (clone $openAppeals)->count();
        $lateAppeals = (clone $openAppeals)->where('created_at', '<', $cutoff)->count();

        $reports = ModerationReport::query()->where('status', 'open')->count();

        return [
            Stat::make('Held for review', (string) $cases)
                ->description($lateCases > 0
                    ? "{$lateCases} past the {$this->promise()} promise"
                    : 'All within the promised window')
                ->descriptionIcon($lateCases > 0
                    ? 'heroicon-m-exclamation-triangle'
                    : 'heroicon-m-check-circle')
                ->color($lateCases > 0 ? 'danger' : 'success')
                ->url('/admin'),

            // Appeals are listed separately from cases on purpose: a person
            // who appealed has already had something taken down and been
            // told they would hear back. Folding them into one queue count
            // hides the ones with a commitment attached.
            Stat::make('Open appeals', (string) $appeals)
                ->description($lateAppeals > 0
                    ? "{$lateAppeals} past the {$this->promise()} promise"
                    : 'All within the promised window')
                ->descriptionIcon($lateAppeals > 0
                    ? 'heroicon-m-exclamation-triangle'
                    : 'heroicon-m-check-circle')
                ->color($lateAppeals > 0 ? 'danger' : 'success'),

            Stat::make('Reports from students', (string) $reports)
                ->description($reports > 0 ? 'Unresolved' : 'Nothing outstanding')
                ->descriptionIcon('heroicon-m-flag')
                ->color($reports > 0 ? 'warning' : 'gray'),
        ];
    }

    private function promise(): string
    {
        return self::PROMISE_HOURS.'h';
    }
}
