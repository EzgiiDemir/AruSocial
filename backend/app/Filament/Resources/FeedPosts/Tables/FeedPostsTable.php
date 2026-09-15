<?php

namespace App\Filament\Resources\FeedPosts\Tables;

use App\Models\FeedPost;
use App\Services\AuditLogger;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FeedPostsTable
{
    public static function configure(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('user.email')->label(__('panel.social.author'))->searchable(),
            TextColumn::make('text')->label(__('panel.social.post'))->limit(90)->wrap()->searchable(),
            TextColumn::make('workflow_status')->label(__('panel.social.workflow'))->badge(),
            TextColumn::make('moderation_status')->label(__('panel.social.moderation'))->badge(),
            IconColumn::make('is_pinned')->label(__('panel.social.pinned'))->boolean(),
            TextColumn::make('created_at')->label(__('panel.social.created'))->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('workflow_status')->options(['draft' => 'Draft', 'pending' => 'Pending', 'published' => 'Published', 'rejected' => 'Hidden']),
            SelectFilter::make('moderation_status')->options(['approved' => 'Approved', 'pending' => 'Pending', 'rejected' => 'Rejected']),
        ])->recordActions([
            Action::make('hide')->label(__('panel.social.hide'))->icon('heroicon-o-eye-slash')->color('danger')->requiresConfirmation()
                ->visible(fn (FeedPost $record) => $record->workflow_status !== 'rejected')
                ->action(function (FeedPost $record): void {
                    $record->workflow_status = 'rejected';
                    $record->save();
                    AuditLogger::logAsCurrentUser('hide', 'feed_post', $record->id);
                }),
            Action::make('publish')->label(__('panel.social.publish'))->icon('heroicon-o-eye')->color('primary')->requiresConfirmation()
                ->visible(fn (FeedPost $record) => $record->workflow_status !== 'published')
                ->action(function (FeedPost $record): void {
                    $record->workflow_status = 'published';
                    $record->save();
                    AuditLogger::logAsCurrentUser('publish', 'feed_post', $record->id);
                }),
        ]);
    }
}
