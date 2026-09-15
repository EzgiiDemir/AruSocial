<?php

namespace App\Filament\Resources\TranslationKeys\Tables;

use App\Models\Translation;
use App\Models\TranslationKey;
use App\Services\Translations\TranslationCatalogue;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TranslationKeysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Without this the missing/unpublished columns run one query per
            // row per language — 594 keys would be thousands of queries to
            // draw one page.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('translations'))
            ->defaultSort('key')
            ->columns([
                TextColumn::make('key')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->copyable()
                    ->description(fn (TranslationKey $r) => $r->description),

                TextColumn::make('area')
                    ->badge()
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                // What a student actually sees today, so the list reads as
                // the app rather than as a database table.
                TextColumn::make('tr')
                    ->label('Türkçe')
                    ->state(fn (TranslationKey $r) => self::published($r, 'tr'))
                    ->limit(44)
                    ->wrap(),

                TextColumn::make('en')
                    ->label('English')
                    ->state(fn (TranslationKey $r) => self::published($r, 'en'))
                    ->limit(44)
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('ru')
                    ->label('Русский')
                    ->state(fn (TranslationKey $r) => self::published($r, 'ru'))
                    ->limit(44)
                    ->wrap()
                    ->toggleable(),

                // The missing-translation warning the brief asks for, on the
                // row rather than in a report nobody opens.
                TextColumn::make('missing')
                    ->label(__('panel.translations.missing'))
                    ->badge()
                    ->color('danger')
                    ->state(fn (TranslationKey $r) => array_map('strtoupper', $r->missingLocales())),

                TextColumn::make('unpublished')
                    ->label(__('panel.translations.unpublished'))
                    ->badge()
                    ->color('warning')
                    ->state(fn (TranslationKey $r) => array_map('strtoupper', $r->unpublishedLocales())),
            ])
            ->filters([
                SelectFilter::make('area')
                    ->options(fn () => TranslationKey::query()
                        ->whereNotNull('area')
                        ->distinct()
                        ->orderBy('area')
                        ->pluck('area', 'area')
                        ->all())
                    ->label('Area'),

                Filter::make('missing')
                    ->label('Missing a translation')
                    ->query(fn (Builder $q) => $q->whereHas('translations', function (Builder $t) {
                        $t->whereNull('published');
                    }, '>=', 1)->orWhereDoesntHave('translations'))
                    ->toggle(),

                Filter::make('unpublished')
                    ->label('Has unpublished edits')
                    ->query(fn (Builder $q) => $q->whereHas('translations', function (Builder $t) {
                        $t->whereNotNull('draft')
                            ->where(function (Builder $w) {
                                $w->whereNull('published')
                                    ->orWhereColumn('draft', '!=', 'published');
                            });
                    }))
                    ->toggle(),

                Filter::make('has_placeholders')
                    ->label('Uses placeholders')
                    ->query(fn (Builder $q) => $q->whereNotNull('placeholders')
                        ->where('placeholders', '!=', '[]'))
                    ->toggle(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),

                Action::make('publish')
                    ->label(__('panel.translations.publish'))
                    ->icon('heroicon-o-rocket-launch')
                    ->color('success')
                    ->visible(fn (TranslationKey $r) => $r->unpublishedLocales() !== [])
                    ->requiresConfirmation()
                    ->modalHeading(__('panel.translations.publish_heading'))
                    ->modalDescription(
                        'Every phone picks this up on its next check. There is no '
                        .'app release involved — and no undo beyond rolling back to '
                        .'a previous version.'
                    )
                    ->action(function (TranslationKey $record) {
                        $published = self::publishDrafts($record);

                        Notification::make()
                            ->title($published === 0
                                ? 'Nothing to publish'
                                : "Published {$published} translation(s)")
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('publish')
                        ->label('Publish drafts')
                        ->icon('heroicon-o-rocket-launch')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Collection $records) {
                            $total = 0;
                            foreach ($records as $record) {
                                $total += self::publishDrafts($record);
                            }

                            Notification::make()
                                ->title("Published {$total} translation(s)")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    ExportBulkAction::make(),

                    // Deleting a key removes the string from every language
                    // and every installed app, which is why it is the only
                    // destructive action here and sits behind a confirmation.
                    DeleteBulkAction::make()
                        ->requiresConfirmation()
                        ->modalDescription(
                            'The app calls these keys by name. Deleting one means '
                            .'every installed build shows nothing where that text was.'
                        ),
                ]),
            ]);
    }

    private static function published(TranslationKey $record, string $locale): ?string
    {
        return $record->translations
            ->firstWhere('locale', $locale)?->published;
    }

    /** @return int how many languages were published */
    private static function publishDrafts(TranslationKey $record): int
    {
        $actor = auth()->user()?->email ?? 'panel';
        $count = 0;

        $record->loadMissing('translations');

        foreach ($record->translations as $translation) {
            if ($translation->hasUnpublishedChanges()) {
                $translation->publish($actor);
                $count++;
            }
        }

        if ($count > 0) {
            TranslationCatalogue::forget();
        }

        return $count;
    }
}
