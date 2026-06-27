<?php

namespace App\Filament\Resources\CommunityTopics\Pages;

use App\Features\CommunityTopic\Actions\DeleteTopic;
use App\Filament\Resources\CommunityTopics\CommunityTopicResource;
use App\Models\CommunityTopic;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCommunityTopic extends ViewRecord
{
    protected static string $resource = CommunityTopicResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(function (CommunityTopic $record): bool {
                    app(DeleteTopic::class)->purge($record);

                    return true;
                })
                ->successRedirectUrl(CommunityTopicResource::getUrl('index')),
        ];
    }
}
