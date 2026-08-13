<?php

namespace App\Filament\Resources\Groups\Pages;

use App\Features\Group\Actions\DeleteGroup;
use App\Filament\Resources\Groups\GroupResource;
use App\Models\Group;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGroup extends EditRecord
{
    protected static string $resource = GroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(function (Group $record): bool {
                    app(DeleteGroup::class)->purge($record);

                    return true;
                })
                ->successRedirectUrl(GroupResource::getUrl('index')),
        ];
    }
}
