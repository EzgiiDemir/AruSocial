<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\CampusSummary;
use App\Filament\Widgets\ModerationAlerts;
use App\Filament\Widgets\QuickActions;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;

/**
 * The Admin panel — full campus content management.
 *
 * Everything about how this looks and behaves lives in [CampusPanel], which
 * the Trainer panel uses too. The brief is that the two differ only in what
 * a person may do; keeping the shared configuration in one place is what
 * stops that drifting apart again.
 *
 * What is specific here is the resource directory and the accent colour.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return CampusPanel::configure($panel, Color::Amber)
            ->default()
            ->id('admin')
            ->path('admin')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            // Ordered by what someone opening the panel needs to know, in
            // that order: is anything waiting for me, what can I do about
            // it, and then the numbers. Filament's own AccountWidget and
            // FilamentInfoWidget are deliberately gone — one repeated the
            // name already in the corner, the other advertised the
            // framework to university staff.
            ->widgets([
                ModerationAlerts::class,
                QuickActions::class,
                CampusSummary::class,
            ]);
    }
}
