<?php

namespace App\Filament\Resources\BannerImages\Pages;

use App\Features\Banner\Actions\DeleteBannerImage;
use App\Features\Banner\Actions\UpdateBannerImage;
use App\Filament\Resources\BannerImages\BannerImageResource;
use App\Models\BannerImage;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

class EditBannerImage extends EditRecord
{
    protected static string $resource = BannerImageResource::class;

    protected function getHeaderActions(): array
    {
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
        $data['placements'] = $this->record->banners()->pluck('banners.id')->all();
        // The upload starts empty: leaving it blank keeps the current image.
        unset($data['image']);

        return $data;
    }

    /**
     * One atomic edit: link/label/placements and an optional replacement image, all in the action's
     * compensating transaction (a failed image swap rolls back the metadata too).
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var BannerImage $record */
        $upload = Arr::first((array) ($data['image'] ?? []));

        return app(UpdateBannerImage::class)(
            $record,
            $data['url'] ?? null,
            $data['name'] ?? null,
            array_map('intval', $data['placements'] ?? []),
            $upload instanceof UploadedFile ? $upload : null,
        );
    }
}
