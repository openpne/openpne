<?php

namespace App\Filament\Resources\BannerImages\Pages;

use App\Features\Banner\Actions\StoreBannerImage;
use App\Filament\Resources\BannerImages\BannerImageResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

class CreateBannerImage extends CreateRecord
{
    protected static string $resource = BannerImageResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * The upload (kept temporary by storeFiles(false)) is stored through FileUploader, the row created,
     * and placements synced — all compensated against a byte orphan by the action.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $upload = Arr::first((array) ($data['image'] ?? []));
        abort_unless($upload instanceof UploadedFile, 422);

        return app(StoreBannerImage::class)(
            $upload,
            $data['url'] ?? null,
            $data['name'] ?? null,
            array_map('intval', $data['placements'] ?? []),
        );
    }
}
