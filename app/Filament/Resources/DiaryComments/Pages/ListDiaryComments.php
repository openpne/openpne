<?php

namespace App\Filament\Resources\DiaryComments\Pages;

use App\Filament\Resources\DiaryComments\DiaryCommentResource;
use Filament\Resources\Pages\ListRecords;

class ListDiaryComments extends ListRecords
{
    protected static string $resource = DiaryCommentResource::class;
}
