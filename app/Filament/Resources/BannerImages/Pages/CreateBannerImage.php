<?php

namespace App\Filament\Resources\BannerImages\Pages;

use App\Features\Banner\Actions\StoreBannerImage;
use App\Filament\Pages\BannerSettings;
use App\Filament\Resources\BannerImages\BannerImageResource;
use App\Files\FormUpload;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateBannerImage extends CreateRecord
{
    protected static string $resource = BannerImageResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /** Point the operator at the Banner page, where the new image is assigned to a placement. */
    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title(__('Banner image added'))
            ->body(__('Choose where it appears on the Banner page.'))
            ->actions([
                Action::make('choosePlacement')
                    ->label(__('Banner'))
                    ->url(BannerSettings::getUrl()),
            ]);
    }

    /**
     * The upload (kept temporary by storeFiles(false)) is stored through FileUploader and the row
     * created, compensated against a byte orphan by the action. The new image starts in no placement;
     * which placements show it is chosen on the Banner page.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $upload = FormUpload::single($data['image'] ?? null);
        abort_unless($upload !== null, 422);

        return app(StoreBannerImage::class)(
            $upload,
            $data['url'] ?? null,
            $data['name'] ?? null,
        );
    }
}
