<?php

namespace App\Filament\Trainer\Widgets;

use App\Filament\Trainer\Resources\Events\EventResource;
use Filament\Widgets\Widget;

/**
 * The one thing a department head comes here to do.
 *
 * Same widget shape and same place on the page as the Admin panel's, so the
 * two dashboards read as one product. The list is shorter because the role
 * is narrower — which is the only difference the brief asks for.
 */
class TrainerQuickActions extends Widget
{
    protected string $view = 'filament.widgets.quick-actions';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return list<array{label: string, icon: string, url: string}>
     */
    public function getActions(): array
    {
        if (! EventResource::canCreate()) {
            return [];
        }

        return [[
            'label' => __('panel.events.section'),
            'icon' => 'heroicon-o-calendar-days',
            'url' => EventResource::getUrl('create'),
        ]];
    }

    public static function canView(): bool
    {
        return true;
    }
}
