<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AuthorizesAicadKnowledge;
use App\Services\Ai\AicadHealth;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** AICAD Health: queue, model, embedder, index freshness, last evaluation, data gaps. Read-only. */
class AicadHealthPage extends Page
{
    use AuthorizesAicadKnowledge;

    protected string $view = 'filament.pages.aicad-health';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.aicad';

    protected static ?int $navigationSort = 18;

    /** @var array<string, array{ok: bool, message: string}>|null Live probes, only after "Check now". */
    public ?array $probes = null;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.aicad');
    }

    public static function getNavigationLabel(): string
    {
        return __('panel.aicad_health.nav');
    }

    public function getTitle(): string
    {
        return __('panel.aicad_health.title');
    }

    public static function canAccess(): bool
    {
        return self::aicadCanRead();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('probe')->label(__('panel.aicad_health.check_now'))->icon('heroicon-o-signal')
                ->action(fn () => $this->probes = app(AicadHealth::class)->probes()),
        ];
    }

    public function health(): AicadHealth
    {
        return app(AicadHealth::class);
    }
}
