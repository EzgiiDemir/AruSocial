<?php

namespace App\Providers\Filament;

use App\Filament\Trainer\Widgets\DepartmentSummary;
use App\Filament\Trainer\Widgets\TrainerQuickActions;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;

/**
 * The Trainer panel — department heads managing their own department.
 *
 * A separate panel rather than a hidden corner of the admin one, because
 * the requirement is that the two are *separated and enforced*, and a
 * separate panel makes that structural: a trainer's session never reaches
 * an admin URL, so a resource that forgets its own authorisation check
 * still is not exposed to them.
 *
 * `discoverResources` points at its own directory for the same reason. An
 * admin resource cannot appear here by being dropped in the wrong folder,
 * which is exactly how panels that share a resource namespace leak.
 *
 * Everything about how it *looks* comes from [CampusPanel], shared with the
 * Admin panel: same logo, same sidebar groups, same layout. The brief is
 * that the difference between the two panels is what you may do, not where
 * the buttons are.
 *
 * Row-level scoping — which department's rows a trainer may see — belongs
 * to the resources themselves and to `EnsureDepartmentHead`. This class
 * only answers "may this account open the Trainer panel at all".
 */
class TrainerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return CampusPanel::configure(
            $panel,
            // Visibly not the admin panel. Someone holding both roles
            // should be able to tell at a glance which one they are in
            // before they change something.
            Color::Teal,
        )
            ->id('trainer')
            ->path('trainer')
            ->discoverResources(
                in: app_path('Filament/Trainer/Resources'),
                for: 'App\Filament\Trainer\Resources',
            )
            ->discoverPages(
                in: app_path('Filament/Trainer/Pages'),
                for: 'App\Filament\Trainer\Pages',
            )
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(
                in: app_path('Filament/Trainer/Widgets'),
                for: 'App\Filament\Trainer\Widgets',
            )
            ->widgets([
                TrainerQuickActions::class,
                DepartmentSummary::class,
            ]);
    }
}
