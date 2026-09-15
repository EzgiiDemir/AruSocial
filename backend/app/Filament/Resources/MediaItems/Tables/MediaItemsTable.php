<?php

namespace App\Filament\Resources\MediaItems\Tables;

use App\Models\MediaItem;
use App\Services\AuditLogger;
use App\Services\GranularPermissions;
use App\Services\Media\MediaAudit;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class MediaItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('uploaded_at', 'desc')
            ->columns([
                // A thumbnail, because a filename is not something anyone
                // can make a delete decision from.
                ImageColumn::make('file_path')
                    ->label(__('panel.media.preview'))
                    ->disk(MediaItem::disk())
                    ->height(48)
                    ->square(),

                TextColumn::make('file_name')
                    ->label(__('panel.media.file'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (MediaItem $r) => $r->mime_type),

                TextColumn::make('uploaded_by')
                    ->label(__('panel.media.uploaded_by'))
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('uploaded_at')
                    ->label(__('panel.media.uploaded_at'))
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('size_bytes')
                    ->label(__('panel.media.size'))
                    ->formatStateUsing(fn ($state) => MediaAudit::humanBytes((int) $state))
                    ->sortable(),

                TextColumn::make('moderation_status')
                    ->label(__('panel.media.status'))
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success',
                        'pending' => 'warning',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                // The answer to "is it safe to delete this", on the row.
                TextColumn::make('used_in')
                    ->label(__('panel.media.used_in'))
                    ->badge()
                    ->color('gray')
                    ->state(fn (MediaItem $r) => self::references($r) ?: [__('panel.media.unused')]),

                TextColumn::make('deleted_at')
                    ->label(__('panel.common.deleted_at'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('moderation_status')
                    ->label(__('panel.media.status'))
                    ->options([
                        'approved' => __('panel.media.approved'),
                        'pending' => __('panel.media.pending'),
                        'rejected' => __('panel.media.rejected'),
                    ]),

                // The cleanup filter: what nothing is using.
                Filter::make('unused')
                    ->label(__('panel.media.unused_only'))
                    ->query(fn (Builder $q) => $q->where(function (Builder $w) {
                        $w->whereNull('used_in')->orWhere('used_in', '[]');
                    }))
                    ->toggle(),

                Filter::make('missing_file')
                    ->label(__('panel.media.missing_file'))
                    ->query(fn (Builder $q) => $q->whereIn('id', self::brokenIds()))
                    ->toggle(),

                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label(__('panel.media.preview'))
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (MediaItem $r) => $r->file_name)
                    ->modalContent(fn (MediaItem $r) => view('filament.media.preview', [
                        'item' => $r,
                        'url' => self::url($r),
                        'references' => self::references($r),
                    ]))
                    ->modalSubmitAction(false),

                DeleteAction::make()
                    // Named before it happens, not after: media is
                    // referenced from posts and covers, and the person
                    // deleting it usually cannot see where.
                    ->modalDescription(fn (MediaItem $r) => self::deletionWarning($r))
                    ->after(fn (MediaItem $r) => self::audit('delete', $r)),

                RestoreAction::make()
                    ->after(fn (MediaItem $r) => self::audit('restore', $r)),

                self::purgeAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make()
                        ->requiresConfirmation()
                        ->modalHeading(__('panel.media.purge_bulk'))
                        ->modalDescription(__('panel.media.purge_body'))
                        ->visible(fn () => self::mayPurge()),
                    ExportBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Permanent deletion, behind two confirmations and its own permission.
     *
     * The brief asks for a clear warning and a second confirmation, and for
     * it to be restricted. The warning names what is using the file; the
     * second confirmation is a checkbox that has to be ticked inside the
     * dialog, because an "are you sure" prompt on its own is dismissed
     * reflexively and this is the one action here that destroys bytes
     * nothing can bring back.
     *
     * Only offered on a row that is already deleted, so purging is always
     * the second decision rather than the first.
     */
    private static function purgeAction(): ForceDeleteAction
    {
        return ForceDeleteAction::make()
            ->label(__('panel.media.purge'))
            ->visible(fn (MediaItem $r) => self::mayPurge() && $r->trashed())
            ->requiresConfirmation()
            ->modalHeading(__('panel.media.purge'))
            ->modalDescription(fn (MediaItem $r) => self::purgeWarning($r))
            ->schema([
                Checkbox::make('understood')
                    ->label(__('panel.media.purge_confirm'))
                    ->accepted()
                    ->required(),
            ])
            ->before(function (MediaItem $r) {
                // The row is about to go; the file has to go with it or the
                // library grows an untracked file every time someone purges.
                Storage::disk(MediaItem::disk())->delete((string) $r->file_path);
            })
            ->after(fn (MediaItem $r) => self::audit('purge', $r));
    }

    /**
     * Permanent deletion is for people who can also manage site settings —
     * the same bar as roles and secrets. Ordinary content editors can
     * delete (recoverable) but not destroy.
     */
    private static function mayPurge(): bool
    {
        $user = auth()->user();

        return $user !== null && GranularPermissions::allows($user, 'users.manage');
    }

    /** @return list<string> */
    private static function references(MediaItem $item): array
    {
        $used = $item->used_in;

        return is_array($used) ? array_map('strval', $used) : array_filter([$used]);
    }

    private static function deletionWarning(MediaItem $item): string
    {
        $references = self::references($item);

        return $references === []
            ? __('panel.media.delete_unused')
            : __('panel.media.delete_used', [
                'count' => count($references),
                'list' => implode(', ', array_slice($references, 0, 5)),
            ]);
    }

    private static function purgeWarning(MediaItem $item): string
    {
        return __('panel.media.purge_body').' '.self::deletionWarning($item);
    }

    /**
     * Rows whose file is gone. Computed rather than stored, because the
     * disk is the authority and a cached flag would go stale silently.
     *
     * @return list<string>
     */
    private static function brokenIds(): array
    {
        return array_column(MediaAudit::run()['broken'], 'id');
    }

    /**
     * The API path that serves the bytes.
     *
     * Built by hand because the route is not named. Not `Storage::url()`:
     * the media disk is private, so a storage URL would 404 — the file is
     * only reachable through the authenticated endpoint.
     */
    private static function url(MediaItem $item): string
    {
        return url('/api/v1/media/'.$item->id.'/file');
    }

    private static function audit(string $action, MediaItem $item): void
    {
        AuditLogger::logAsCurrentUser($action, 'media', (string) $item->file_name);
    }
}
