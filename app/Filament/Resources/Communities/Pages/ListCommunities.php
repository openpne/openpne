<?php

namespace App\Filament\Resources\Communities\Pages;

use App\Filament\Resources\Communities\CommunityResource;
use Filament\Resources\Pages\ListRecords;

class ListCommunities extends ListRecords
{
    protected static string $resource = CommunityResource::class;
}
