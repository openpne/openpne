<?php

namespace App\Filament\Resources\CommunityEvents\Pages;

use App\Filament\Resources\CommunityEvents\CommunityEventResource;
use Filament\Resources\Pages\ListRecords;

class ListCommunityEvents extends ListRecords
{
    protected static string $resource = CommunityEventResource::class;
}
