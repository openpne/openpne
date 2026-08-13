<?php

namespace App\Filament\Resources\GroupEvents\Pages;

use App\Features\GroupEvent\Actions\DeleteEvent;
use App\Filament\Resources\GroupEvents\GroupEventResource;
use App\Models\GroupEvent;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewGroupEvent extends ViewRecord
{
    protected static string $resource = GroupEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(function (GroupEvent $record): bool {
                    app(DeleteEvent::class)->purge($record);

                    return true;
                })
                ->successRedirectUrl(GroupEventResource::getUrl('index')),
        ];
    }
}
