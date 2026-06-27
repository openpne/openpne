<?php

namespace App\Filament\Resources\Communities\Pages;

use App\Features\Community\Actions\DeleteCommunity;
use App\Filament\Resources\Communities\CommunityResource;
use App\Models\Community;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCommunity extends EditRecord
{
    protected static string $resource = CommunityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(function (Community $record): bool {
                    app(DeleteCommunity::class)->purge($record);

                    return true;
                })
                ->successRedirectUrl(CommunityResource::getUrl('index')),
        ];
    }
}
