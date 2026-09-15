<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Services\GranularPermissions;
use App\Services\Translations\TranslationCatalogue;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        return $user instanceof User && GranularPermissions::allows($user, 'stats.view');
    }

    private function count(string $table, ?callable $filter = null): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }
        $query = DB::table($table);
        if ($filter) {
            $filter($query);
        }

        return $query->count();
    }

    private function stat(string $label, int|string $value, string $icon, bool $attention = false): Stat
    {
        return Stat::make($label, (string) $value)->descriptionIcon($icon)->color($attention ? 'primary' : 'gray');
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return [];
        }

        $stats = [
            $this->stat(__('panel.dashboard.total_users'), $this->count('users'), 'heroicon-m-users'),
            $this->stat(__('panel.dashboard.active_users'), $this->count('users', fn (Builder $q) => $q->where('account_status', 'active')), 'heroicon-m-user-group'),
            $this->stat(__('panel.dashboard.active_today'), Schema::hasTable('activity_log') ? DB::table('activity_log')->whereDate('created_at', today())->distinct()->count('user_id') : 0, 'heroicon-m-bolt'),
            $this->stat(__('panel.dashboard.new_registrations'), $this->count('users', fn (Builder $q) => $q->where('created_at', '>=', now()->subDays(7))), 'heroicon-m-user-plus'),
        ];

        if (GranularPermissions::allows($user, 'applications.manage')) {
            $pending = $this->count('participation_applications', fn (Builder $q) => $q->whereIn('status', ['pending', 'submitted', 'under_review']));
            $stats[] = $this->stat(__('panel.dashboard.pending_applications'), $pending, 'heroicon-m-inbox-arrow-down', $pending > 0);
        }
        if (GranularPermissions::allows($user, 'appointments.manage')) {
            $stats[] = $this->stat(__('panel.dashboard.upcoming_appointments'), $this->count('appointments', fn (Builder $q) => $q->where('slot_date', '>=', today())->where('status', 'booked')), 'heroicon-m-clock');
        }
        if (GranularPermissions::allows($user, 'events.manage')) {
            $stats[] = $this->stat(__('panel.dashboard.active_events'), $this->count('events', fn (Builder $q) => $q->where('workflow_status', 'published')), 'heroicon-m-calendar-days');
            $stats[] = $this->stat(__('panel.dashboard.event_participation'), $this->count('event_joins'), 'heroicon-m-ticket');
        }
        if (GranularPermissions::allows($user, 'clubs.manage')) {
            $stats[] = $this->stat(__('panel.dashboard.active_clubs'), $this->count('clubs'), 'heroicon-m-user-group');
        }
        if (GranularPermissions::allows($user, 'sports.manage')) {
            $stats[] = $this->stat(__('panel.dashboard.sports_activities'), $this->count('sports'), 'heroicon-m-trophy');
        }
        if (Schema::hasTable('support_tickets')) {
            $stats[] = $this->stat(__('panel.dashboard.open_tickets'), $this->count('support_tickets', fn (Builder $q) => $q->whereNotIn('status', ['closed', 'resolved'])), 'heroicon-m-lifebuoy', true);
        }

        if (GranularPermissions::allows($user, 'moderation.moderate')) {
            $waiting = $this->count('moderation_cases', fn (Builder $q) => $q->whereIn('status', ['open', 'reviewing']));
            $stats[] = $this->stat(__('panel.dashboard.moderation_waiting'), $waiting, 'heroicon-m-shield-exclamation', $waiting > 0);
        }

        if (GranularPermissions::allows($user, 'pages.manage')) {
            $missing = collect(TranslationCatalogue::missing())->flatten()->count();
            $stats[] = $this->stat(__('panel.dashboard.missing_translations'), $missing, 'heroicon-m-language', $missing > 0);
            $stats[] = $this->stat(__('panel.dashboard.published_pages'), $this->count('admin_pages', fn (Builder $q) => $q->where('status', 'published')->whereNull('deleted_at')), 'heroicon-m-globe-alt');
            $stats[] = $this->stat(__('panel.dashboard.draft_content'), $this->count('admin_pages', fn (Builder $q) => $q->where('status', 'draft')->whereNull('deleted_at')), 'heroicon-m-pencil-square');
            $review = $this->count('admin_pages', fn (Builder $q) => $q->where('status', 'review')->whereNull('deleted_at'));
            $stats[] = $this->stat(__('panel.dashboard.awaiting_approval'), $review, 'heroicon-m-document-magnifying-glass', $review > 0);
        }

        if (GranularPermissions::allows($user, 'activityLog.view')) {
            $failed = $this->count('email_logs', fn (Builder $q) => $q->where('status', 'failed'));
            $stats[] = $this->stat(__('panel.dashboard.failed_emails'), $failed, 'heroicon-m-envelope', $failed > 0);
            $bytes = Schema::hasTable('media_items') ? (int) DB::table('media_items')->sum('size_bytes') : 0;
            $stats[] = $this->stat(__('panel.dashboard.storage_usage'), number_format($bytes / 1048576, 1).' MB', 'heroicon-m-circle-stack');
            $stats[] = $this->stat(__('panel.dashboard.system_health'), __('panel.dashboard.operational'), 'heroicon-m-heart');
        }

        return $stats;
    }
}
