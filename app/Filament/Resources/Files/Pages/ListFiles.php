<?php

namespace App\Filament\Resources\Files\Pages;

use App\Filament\Resources\Files\FileResource;
use App\Files\FileUploader;
use App\Files\FormUpload;
use App\Models\File;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

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
            // Upload a standalone public image (no owning entity) to embed in custom HTML/CSS. Stored
            // through FileUploader as explicit_visibility='public' so PublicFileController serves it to
            // guests; the list reloads after the action so the new file shows here. Raster only, ≤5 MB,
            // dimension-capped (the banner upload rules); the temp upload is handed to FileUploader.
            Action::make('uploadImage')
                ->label(__('Upload image'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->modalDescription(__('Upload a public image to embed in custom HTML/CSS. The upload returns a login-free URL.'))
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
                    $upload = FormUpload::single($data['image'] ?? null);
                    abort_unless($upload !== null, 422);

                    $file = app(FileUploader::class)->store($upload, explicitVisibility: File::VISIBILITY_PUBLIC);

                    Notification::make()
                        ->success()
                        ->title(__('Image uploaded'))
                        ->body(__('Public URL: :url', ['url' => $file->publicUrl()]))
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
