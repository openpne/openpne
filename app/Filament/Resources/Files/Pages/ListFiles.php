<?php

namespace App\Filament\Resources\Files\Pages;

use App\Filament\Resources\Files\FileResource;
use App\Files\FileUploader;
use App\Models\File;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

class ListFiles extends ListRecords
{
    protected static string $resource = FileResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        $maxDimension = (int) config('openpne.images.max_upload_dimension', 5000);

        return [
            // Upload a standalone public image (no owning entity) for embedding in custom HTML/CSS.
            // Stored through FileUploader as explicit_visibility='public' so FilePolicy serves it to
            // guests. Mirrors the banner upload rules (raster only, ≤5 MB, dimension-capped); the temp
            // upload (storeFiles(false)) is handed to FileUploader, not kept on a Filament disk.
            Action::make('uploadImage')
                ->label(__('Upload image'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->schema([
                    FileUpload::make('image')
                        ->label(__('Image'))
                        ->image()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
                        ->maxSize(5120)
                        ->rules(["dimensions:max_width={$maxDimension},max_height={$maxDimension}"])
                        ->storeFiles(false)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $upload = Arr::first((array) ($data['image'] ?? []));
                    abort_unless($upload instanceof UploadedFile, 422);

                    $file = app(FileUploader::class)->store($upload, explicitVisibility: File::VISIBILITY_PUBLIC);

                    Notification::make()
                        ->success()
                        ->title(__('Image uploaded'))
                        ->body(__('Public URL: :url', ['url' => $file->url()]))
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
