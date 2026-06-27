<?php

namespace App\Filament\Resources\CommunityTopics\Pages;

use App\Filament\Resources\CommunityTopics\CommunityTopicResource;
use Filament\Resources\Pages\ListRecords;

class ListCommunityTopics extends ListRecords
{
    protected static string $resource = CommunityTopicResource::class;
}
