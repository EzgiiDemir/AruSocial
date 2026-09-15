<?php

namespace App\Providers\Filament;

use App\Http\Middleware\SetPanelLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Everything the Admin and Trainer panels have in common.
 *
 * The brief is that the two panels should differ only in what a person is
 * allowed to do — not in how they look, where things are, or how the
 * sidebar is organised. Before this they were configured separately and had
 * already drifted: different widget sets, no logo on either, and navigation
 * groups that existed in one and not the other.
 *
 * Keeping the shared configuration in one place is what makes that promise
 * hold over time. A panel option added here reaches both; an option added
 * to one provider is a deliberate difference and reads like one.
 *
 * The accent colour is the exception, and stays per-panel on purpose:
 * someone who holds both roles needs to be able to tell at a glance which
 * one they are in before they change something.
 */
class CampusPanel
{
    /**
     * The sidebar's shape, shared by both panels.
     *
     * Groups are declared here rather than inferred from whatever the
     * resources happen to set, so the order is deliberate and the same
     * everywhere. Filament hides a group with no visible items, so a
     * trainer simply does not see the ones they cannot use — the menu
     * adapts to permissions without any per-role menu code.
     *
     * @return list<NavigationGroup>
     */
    public static function navigationGroups(): array
    {
        return [
            NavigationGroup::make(__('panel.groups.content_management'))
                ->icon('heroicon-o-document-text')
                ->collapsible(),
            NavigationGroup::make(__('panel.groups.social'))
                ->icon('heroicon-o-chat-bubble-left-right')
                ->collapsed(),
            NavigationGroup::make(__('panel.groups.aicad'))
                ->icon('heroicon-o-sparkles')
                ->collapsed(),
            NavigationGroup::make(__('panel.groups.users'))
                ->icon('heroicon-o-users')
                ->collapsed(),
            NavigationGroup::make(__('panel.groups.campus_map'))
                ->icon('heroicon-o-map')
                ->collapsed(),
            NavigationGroup::make(__('panel.groups.operations'))
                ->icon('heroicon-o-building-office-2')
                ->collapsed(),
            NavigationGroup::make(__('panel.groups.system'))
                ->icon('heroicon-o-cog-6-tooth')
                ->collapsed(),
        ];
    }

    /**
     * Applies the shared look, layout and middleware.
     */
    public static function configure(Panel $panel, array $primary): Panel
    {
        return $panel
            ->login()
            ->colors([
                'primary' => $primary,
                'danger' => Color::Red,
                'warning' => Color::Amber,
                'success' => Color::Blue,
                'gray' => Color::Zinc,
            ])
            ->brandLogo(asset('images/arucad-logo.png'))
            ->brandLogoHeight('1.9rem')
            ->favicon(asset('images/arucad-logo.png'))
            ->navigationGroups(self::navigationGroups())
            // Collapsible on desktop: these tables are wide, and a sidebar
            // that cannot get out of the way costs a column of data on a
            // laptop screen.
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(Width::Full)
            // Deliberately no ->databaseNotifications(): Filament's bell
            // reads Laravel's standard `notifications` table, and this app
            // has its own table of that name with a different shape
            // (`user_id`, no `notifiable_type`). Turning it on made every
            // panel page 500 on a missing column.
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // After Authenticate on purpose: the locale comes from the
                // signed-in account's own preference, so the user has to be
                // resolved first.
                SetPanelLocale::class,
            ]);
    }
}
