<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Ai\InstitutionProfile;
use App\Services\AuditLogger;
use App\Services\GranularPermissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * What AICAD says when a student asks who ARUCAD is.
 *
 * Separate from Crawl sources on purpose. That screen says which sites to
 * READ; this one says what is TRUE regardless of what any page happens to
 * say. The crawler can only answer "what does this page contain", and the
 * university's identity is not on any single page — so the assistant used to
 * assemble it from whichever news article ranked highest.
 *
 * Gated by the same permission as the rest of the AI plumbing: reading needs
 * `system.integration.read`, saving needs `system.integration.manage_settings`.
 */
class InstitutionProfilePage extends Page
{
    protected string $view = 'filament.pages.institution-profile';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.system';

    protected static ?int $navigationSort = 34;

    public ?string $profile = null;

    public static function getNavigationLabel(): string
    {
        return __('panel.institution_profile.nav');
    }

    public function getTitle(): string
    {
        return __('panel.institution_profile.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && GranularPermissions::allows($user, 'system.integration.read');
    }

    private function mayEdit(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && GranularPermissions::allows($user, 'system.integration.manage_settings');
    }

    public function mount(): void
    {
        $this->profile = app(InstitutionProfile::class)->text();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('panel.institution_profile.section'))
                ->description(__('panel.institution_profile.hint'))
                ->schema([
                    Textarea::make('profile')
                        ->hiddenLabel()
                        ->rows(24)
                        ->maxLength(20000)
                        ->disabled(! $this->mayEdit())
                        ->helperText(__('panel.institution_profile.rules')),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('panel.institution_profile.save'))
                ->icon('heroicon-o-check')
                ->visible(fn (): bool => $this->mayEdit())
                ->action(function (): void {
                    $service = app(InstitutionProfile::class);
                    $service->save($this->profile);

                    // A change here changes what the assistant tells every
                    // student about the university, so it is an auditable act.
                    AuditLogger::logAsCurrentUser(
                        'ai.institution_profile.updated',
                        'setting',
                        InstitutionProfile::SETTING_KEY,
                    );

                    Notification::make()
                        ->success()
                        ->title(__('panel.institution_profile.saved'))
                        ->body(__('panel.institution_profile.saved_body'))
                        ->send();
                }),

            Action::make('restore')
                ->label(__('panel.institution_profile.restore'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('panel.institution_profile.restore_confirm'))
                ->visible(fn (): bool => $this->mayEdit())
                ->action(function (): void {
                    $service = app(InstitutionProfile::class);
                    // Clearing the stored override is what restores the
                    // shipped text; writing it back as a value would freeze
                    // today's default and stop future releases updating it.
                    $service->save(null);
                    $this->profile = $service->text();

                    Notification::make()
                        ->success()
                        ->title(__('panel.institution_profile.restored'))
                        ->send();
                }),
        ];
    }
}
