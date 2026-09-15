<?php

namespace App\Filament\Resources\FeedPosts\Pages;

use App\Filament\Resources\FeedPosts\FeedPostResource;
use Filament\Resources\Pages\ListRecords;

class ListFeedPosts extends ListRecords
{
    protected static string $resource = FeedPostResource::class;
}
