<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Files\FileUploader;
use App\Models\File;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

/**
 * Upload a standalone public image (no owning entity) to embed in custom HTML/CSS. Stored through
 * FileUploader as explicit_visibility='public' so FilePolicy / PublicFileController serve it to guests;
 * the resulting login-free URL is shown for the operator to paste in.
 *
 * A page (not a header action on the Files list) because the upload must reach FileUploader as a live
 * uploaded file: the page form's getState() rehydrates the temp upload the same way the banner upload
 * does, which an action modal's serialized data did not.
 *
 * @property-read Schema $form
 */
class UploadImage extends Page
{
    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedArrowUpTray;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Content');
    }

    public static function getNavigationLabel(): string
    {
        return __('Upload image');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Upload image');
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        $maxDimension = (int) config('openpne.images.max_upload_dimension', 5000);

        return $schema
            ->components([
                Section::make(__('Upload image'))
                    ->description(__('Upload a public image to embed in custom HTML/CSS. The upload returns a login-free URL.'))
                    ->schema([
                        FileUpload::make('image')
                            ->label(__('Image'))
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
                            ->maxSize(5120)
                            ->rules(["dimensions:max_width={$maxDimension},max_height={$maxDimension}"])
                            ->storeFiles(false)
                            ->required(),
                    ]),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            // Not 'upload': that is a reserved Livewire JS method ($wire.upload), so wire:submit="upload"
            // would invoke the file-upload manager instead of this handler.
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')
                        ->label(__('Upload'))
                        ->submit('save')
                        ->keyBindings(['mod+s']),
                ]),
            ]);
    }

    public function save(): void
    {
        $upload = Arr::first((array) ($this->form->getState()['image'] ?? []));
        abort_unless($upload instanceof UploadedFile, 422);

        $file = app(FileUploader::class)->store($upload, explicitVisibility: File::VISIBILITY_PUBLIC);

        Notification::make()
            ->success()
            ->title(__('Image uploaded'))
            ->body(__('Public URL: :url', ['url' => $file->publicUrl()]))
            ->persistent()
            ->send();

        $this->form->fill();
    }
}
