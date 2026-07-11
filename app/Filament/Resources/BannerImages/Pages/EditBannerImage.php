<?php

namespace App\Filament\Resources\BannerImages\Pages;

use App\Features\Banner\Actions\DeleteBannerImage;
use App\Features\Banner\Actions\UpdateBannerImage;
use App\Filament\Resources\BannerImages\BannerImageResource;
use App\Files\FormUpload;
use App\Files\ImageMetadataStripException;
use App\Models\BannerImage;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditBannerImage extends EditRecord
{
    protected static string $resource = BannerImageResource::class;

    protected function getHeaderActions(): array
    {
        // Full size is opened by clicking the preview image (shared lightbox), not a header action.
        return [
            DeleteAction::make()
                ->action(fn (BannerImage $record) => app(DeleteBannerImage::class)($record)),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        // The upload starts empty: leaving it blank keeps the current image.
        unset($data['image']);

        return $data;
    }

    /**
     * One atomic edit: link/label and an optional replacement image, in the action's compensating
     * transaction (a failed image swap rolls back the metadata too). Placements are not touched here —
     * they are chosen on the Banner page — so null leaves them as they are.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var BannerImage $record */
        // Null when the upload was left blank — keep the current image.
        $upload = FormUpload::single($data['image'] ?? null);

        try {
            return app(UpdateBannerImage::class)(
                $record,
                $data['url'] ?? null,
                $data['name'] ?? null,
                null,
                $upload,
            );
        } catch (ImageMetadataStripException) {
            // Surface a fail-closed strip as a danger notification + halt (Filament rolls the edit
            // back) rather than a 500; the current image is untouched.
            Notification::make()->danger()->title(ImageMetadataStripException::userMessage())->send();

            throw new Halt;
        }
    }
}
