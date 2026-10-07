<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AuthorizesAicadKnowledge;
use App\Filament\Resources\AiEvaluationCases\AiEvaluationCaseResource;
use App\Models\AiEvaluationCase;
use App\Services\Ai\AskDiagnostics;
use App\Services\Ai\Evaluation\AssertionSuggester;
use App\Support\QueryLanguage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Search Playground: ask AICAD a question and see every stage — follow-up
 * rewriting, routing, resolved entities, database rows, knowledge ranking
 * with score components, prompt budget, model and grounding.
 *
 * Retrieval mode never calls the model, so a retrieval bug can be found
 * without being mistaken for a model bug. See AskDiagnostics.
 */
class AskPlayground extends Page
{
    use AuthorizesAicadKnowledge;

    protected string $view = 'filament.pages.ask-playground';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.aicad';

    protected static ?int $navigationSort = 19;

    public ?string $question = null;

    /** Earlier user turns, one per line, for follow-up questions. */
    public ?string $previous = null;

    public string $mode = AskDiagnostics::MODE_RETRIEVAL;

    public bool $allowWeb = false;

    /** @var array<string, mixed>|null */
    public ?array $report = null;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.aicad');
    }

    public static function getNavigationLabel(): string
    {
        return __('panel.playground.nav');
    }

    public function getTitle(): string
    {
        return __('panel.playground.title');
    }

    public static function canAccess(): bool
    {
        return self::aicadCanRead();
    }

    /** Pre-fill from a link (an evaluation failure's "open in Playground"). */
    public function mount(): void
    {
        $this->question = request()->string('question')->toString() ?: null;
        $this->previous = request()->string('previous')->toString() ?: null;
        if (request()->string('mode')->toString() === AskDiagnostics::MODE_FULL) {
            $this->mode = AskDiagnostics::MODE_FULL;
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    Textarea::make('question')
                        ->label(__('panel.playground.question'))
                        ->required()
                        ->rows(2)
                        ->maxLength(1000)
                        ->columnSpanFull(),
                    Textarea::make('previous')
                        ->label(__('panel.playground.previous'))
                        ->helperText(__('panel.playground.previous_hint'))
                        ->rows(2)
                        ->maxLength(3000)
                        ->columnSpanFull(),
                    Select::make('mode')
                        ->label(__('panel.playground.mode'))
                        ->options([
                            AskDiagnostics::MODE_RETRIEVAL => __('panel.playground.mode_retrieval'),
                            AskDiagnostics::MODE_FULL => __('panel.playground.mode_full'),
                        ])
                        ->required()
                        ->native(false),
                    Toggle::make('allowWeb')
                        ->label(__('panel.playground.allow_web'))
                        ->helperText(__('panel.playground.allow_web_hint')),
                ])
                ->columns(2),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('run')
                ->label(__('panel.playground.run'))
                ->icon('heroicon-o-play')
                ->action(fn () => $this->run()),
            $this->saveAsTestAction(),
        ];
    }

    public function run(): void
    {
        $this->validate([
            'question' => ['required', 'string', 'max:1000'],
            'previous' => ['nullable', 'string', 'max:3000'],
            'mode' => ['required', 'in:'.AskDiagnostics::MODE_RETRIEVAL.','.AskDiagnostics::MODE_FULL],
        ]);

        $history = $this->historyTurns();

        $this->report = app(AskDiagnostics::class)->run(
            trim((string) $this->question),
            $this->mode,
            $history,
            $this->allowWeb,
        );
    }

    /**
     * Save as Evaluation Test: the current question, context and mode, with
     * PROPOSED assertions the admin edits before saving — see
     * AssertionSuggester for why the proposal is deliberately loose.
     */
    private function saveAsTestAction(): Action
    {
        return Action::make('saveAsTest')
            ->label(__('panel.playground.save_as_test'))
            ->icon('heroicon-o-check-badge')
            ->color('gray')
            ->visible(fn (): bool => $this->report !== null && AiEvaluationCaseResource::aicadCanManage())
            ->modalHeading(__('panel.playground.save_as_test'))
            ->modalDescription(__('panel.playground.save_as_test_hint'))
            ->modalWidth('5xl')
            ->fillForm(fn (): array => [
                'name' => mb_substr((string) $this->question, 0, 120),
                'locale' => QueryLanguage::detect((string) $this->question),
                'mode' => $this->mode === AskDiagnostics::MODE_FULL ? AiEvaluationCase::MODE_FULL : AiEvaluationCase::MODE_RETRIEVAL,
                'tags' => ['playground'],
                'assertions' => app(AssertionSuggester::class)->suggest((array) $this->report),
            ])
            ->schema([
                TextInput::make('name')->label(__('panel.aicad_tests.name'))->required()->maxLength(191),
                Select::make('locale')->label(__('panel.aicad_aliases.locale'))
                    ->options(['tr' => 'Türkçe', 'en' => 'English', 'ru' => 'Русский'])->placeholder('—'),
                Select::make('mode')->label(__('panel.playground.mode'))->options(AiEvaluationCaseResource::modeOptions())->required(),
                TagsInput::make('tags')->label(__('panel.aicad_tests.tags')),
                AiEvaluationCaseResource::assertionsRepeater(),
            ])
            ->action(function (array $data): void {
                $case = AiEvaluationCase::create([
                    'name' => $data['name'],
                    'question' => trim((string) $this->question),
                    'locale' => $data['locale'] ?? null,
                    'mode' => $data['mode'],
                    'tags' => $data['tags'] ?? [],
                    'previous_turns' => $this->historyTurns() ?: null,
                    'assertions' => array_values($data['assertions'] ?? []),
                    'created_by' => auth()->user()?->email,
                ]);
                Notification::make()->success()
                    ->title(__('panel.playground.saved_as_test'))
                    ->actions([Action::make('open')->label(__('panel.aicad_tests.open_case'))
                        ->url(AiEvaluationCaseResource::getUrl('edit', ['record' => $case]))])
                    ->send();
            });
    }

    /** @return list<array{role: string, content: string}> */
    private function historyTurns(): array
    {
        $turns = [];
        foreach (preg_split('/\R/u', (string) $this->previous) ?: [] as $line) {
            if (trim($line) !== '') {
                $turns[] = ['role' => 'user', 'content' => trim($line)];
            }
        }

        return $turns;
    }

    /** Stage data by name, for the view's dedicated panels. */
    public function stage(string $name): ?array
    {
        foreach (array_reverse($this->report['stages'] ?? []) as $stage) {
            if ($stage['stage'] === $name) {
                return $stage['data'];
            }
        }

        return null;
    }

    /** @return list<array{stage: string, ms: float, data: array<string, mixed>}> Tool rows, one per selected tool. */
    public function toolStages(): array
    {
        return array_values(array_filter(
            $this->report['stages'] ?? [],
            fn (array $s) => str_starts_with($s['stage'], 'tool.'),
        ));
    }
}
