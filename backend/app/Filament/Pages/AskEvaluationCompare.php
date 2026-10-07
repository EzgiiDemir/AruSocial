<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AuthorizesAicadKnowledge;
use App\Models\AiEvaluationRun;
use App\Services\Ai\Evaluation\EvaluationComparator;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Compare two evaluation runs: what became better or worse, case by case and in aggregate. */
class AskEvaluationCompare extends Page
{
    use AuthorizesAicadKnowledge;

    protected string $view = 'filament.pages.ask-evaluation-compare';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.aicad';

    protected static ?int $navigationSort = 25;

    public ?int $baseline = null;

    public ?int $current = null;

    /** @var array<string, mixed>|null */
    public ?array $diff = null;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.aicad');
    }

    public static function getNavigationLabel(): string
    {
        return __('panel.aicad_runs.compare');
    }

    public function getTitle(): string
    {
        return __('panel.aicad_runs.compare_title');
    }

    public static function canAccess(): bool
    {
        return self::aicadCanRead();
    }

    public function mount(): void
    {
        $current = request()->integer('current') ?: AiEvaluationRun::query()->where('status', 'finished')->max('id');
        $this->current = $current ?: null;
        if ($this->current !== null) {
            $run = AiEvaluationRun::query()->find($this->current);
            // Default baseline: the previous finished run of the same mode.
            $this->baseline = request()->integer('baseline') ?: AiEvaluationRun::query()
                ->where('status', 'finished')->where('id', '<', $this->current)
                ->when($run, fn ($q) => $q->where('mode', $run->mode))
                ->max('id');
        }
        $this->compare();
    }

    public function form(Schema $schema): Schema
    {
        $options = fn () => AiEvaluationRun::query()->where('status', 'finished')->orderByDesc('id')->limit(100)->get()
            ->mapWithKeys(fn (AiEvaluationRun $r) => [$r->id => $r->label().' — '.$r->passed.'/'.($r->passed + $r->failed)])->all();

        return $schema->components([
            Section::make()->schema([
                Select::make('baseline')->label(__('panel.aicad_runs.baseline'))->options($options)->live()
                    ->afterStateUpdated(fn () => $this->compare()),
                Select::make('current')->label(__('panel.aicad_runs.current'))->options($options)->live()
                    ->afterStateUpdated(fn () => $this->compare()),
            ])->columns(2),
        ]);
    }

    public function compare(): void
    {
        $a = $this->baseline ? AiEvaluationRun::query()->find($this->baseline) : null;
        $b = $this->current ? AiEvaluationRun::query()->find($this->current) : null;
        $this->diff = $a && $b ? app(EvaluationComparator::class)->compare($a, $b) : null;
    }
}
