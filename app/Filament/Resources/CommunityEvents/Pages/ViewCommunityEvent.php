<?php

namespace App\Filament\Resources\CommunityEvents\Pages;

use App\Features\CommunityEvent\Actions\DeleteEvent;
use App\Filament\Resources\CommunityEvents\CommunityEventResource;
use App\Models\CommunityEvent;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCommunityEvent extends ViewRecord
{
    protected static string $resource = CommunityEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(function (CommunityEvent $record): bool {
                    app(DeleteEvent::class)->purge($record);

                    return true;
                })
                ->successRedirectUrl(CommunityEventResource::getUrl('index')),
        ];
    }
}
