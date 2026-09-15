<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\ManagesCampusContent;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Places\PlaceResource;
use App\Filament\Resources\TranslationKeys\TranslationKeyResource;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * The three or four things staff actually come here to do.
 *
 * A panel with twenty resources in the sidebar makes the common case — add
 * next week's event — the same number of clicks as the rarest one. These
 * are shortcuts to the create screens people reach for most, on the page
 * they land on.
 *
 * Every action is filtered through the resource's own `canCreate()`, so the
 * list adapts to the signed-in account rather than being a fixed menu with
 * some entries that error on click. A trainer who cannot create places
 * simply does not see the button.
 */
class QuickActions extends Widget
{
    protected string $view = 'filament.widgets.quick-actions';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return list<array{label: string, icon: string, url: string}>
     */
    public function getActions(): array
    {
        $candidates = [
            [EventResource::class, 'panel.events.section', 'heroicon-o-calendar-days'],
            [PlaceResource::class, 'panel.places.section', 'heroicon-o-map-pin'],
            [TranslationKeyResource::class, 'panel.translations.nav', 'heroicon-o-language'],
        ];

        $actions = [];

        foreach ($candidates as [$resource, $label, $icon]) {
            if (! $this->isAvailable($resource)) {
                continue;
            }

            $actions[] = [
                'label' => __($label),
                'icon' => $icon,
                'url' => $resource::getUrl('create'),
            ];
        }

        return $actions;
    }

    /**
     * Registered in *this* panel and permitted for *this* account.
     *
     * Both checks matter. The resource classes are shared code, so asking
     * only "may I create one" would offer a trainer a link into the admin
     * panel, which then refuses them at the door.
     *
     * @param  class-string  $resource
     */
    private function isAvailable(string $resource): bool
    {
        if (! in_array($resource, Filament::getCurrentOrDefaultPanel()?->getResources() ?? [], true)) {
            return false;
        }

        if (! in_array(ManagesCampusContent::class, class_uses_recursive($resource), true)
            && ! method_exists($resource, 'canCreate')) {
            return false;
        }

        return $resource::canCreate();
    }

    public static function canView(): bool
    {
        return true;
    }
}
