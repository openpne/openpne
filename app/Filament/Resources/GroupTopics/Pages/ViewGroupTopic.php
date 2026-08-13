<?php

namespace App\Filament\Resources\GroupTopics\Pages;

use App\Features\GroupTopic\Actions\DeleteTopic;
use App\Filament\Resources\GroupTopics\GroupTopicResource;
use App\Models\GroupTopic;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewGroupTopic extends ViewRecord
{
    protected static string $resource = GroupTopicResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(function (GroupTopic $record): bool {
                    app(DeleteTopic::class)->purge($record);

                    return true;
                })
                ->successRedirectUrl(GroupTopicResource::getUrl('index')),
        ];
    }
}
