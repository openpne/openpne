<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Features\Branding\Actions\SaveBrandingSettings;
use App\Files\FormUpload;
use App\Files\ImageMetadataStripException;
use App\Services\SnsSettingService;
use App\Support\BrandColor;
use App\Support\SnsSettingKey;
use App\Support\SurfaceResolver;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Image;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\UploadedFile;

/**
 * Edit the site's branding: the brand color, the logo mark and the favicon.
 *
 * The upload fields start empty on every visit and leaving one blank keeps the stored file, so the
 * form carries a removal toggle rather than a "clear" gesture on the field. Persisting is
 * App\Features\Branding\Actions\SaveBrandingSettings — the uploads and the settings write have to
 * succeed or fail together.
 *
 * The stored tokens are not existence-checked on render (hot path): deleting the referenced File from
 * the Files resource leaves a dangling URL until a new image is saved here — the same accepted risk
 * as an ownerless public asset embedded in custom HTML/CSS.
 *
 * @property-read Schema $form
 */
class BrandingSettings extends Page
{
    protected static ?int $navigationSort = 6;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedSwatch;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('Branding settings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Branding settings');
    }

    public function mount(): void
    {
        $this->form->fill($this->currentValues());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(SnsSettingKey::BrandColor->label())
                    ->schema([
                        ColorPicker::make(SnsSettingKey::BrandColor->value)
                            ->label(SnsSettingKey::BrandColor->label())
                            ->helperText(self::surfaceScoped(
                                __('Applies to the Modern member screens and the browser chrome. Leave it blank for the built-in color.'),
                                __('Applies to the member screens and the browser chrome. Leave it blank for the built-in color.'),
                            ))
                            // Blank-tolerant: the stored '' means "unbranded". Wrapped in a no-arg
                            // factory so Filament passes the closure through as a validation rule
                            // instead of injecting its ($attribute, $value, $fail) arguments.
                            ->rules([
                                fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                    $hex = is_string($value) ? trim($value) : '';

                                    if ($hex !== '' && ! BrandColor::isValid($hex)) {
                                        $fail(__('Enter a color as a 6-digit hex code, for example #2563eb.'));
                                    }
                                },
                            ]),
                    ]),

                $this->fileSection(
                    SnsSettingKey::BrandLogoFile,
                    brand_logo_url(...),
                    self::surfaceScoped(
                        __('Shown as the brand mark in the Modern member screens. A square image is recommended: it renders in a square slot and is cropped to fill. Classic keeps its text logo.'),
                        __('Shown as the brand mark in the member screens. A square image is recommended: it renders in a square slot and is cropped to fill.'),
                    ),
                    FileUpload::make(SnsSettingKey::BrandLogoFile->value)
                        ->label(__('Upload a new logo'))
                        ->image()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
                        ->maxSize(5120)
                        ->rules(['dimensions:max_width='.self::maxDimension().',max_height='.self::maxDimension()])
                        ->storeFiles(false),
                    'remove_brand_logo',
                    __('Remove the current logo'),
                ),

                $this->fileSection(
                    SnsSettingKey::BrandFaviconFile,
                    brand_favicon_url(...),
                    self::surfaceScoped(
                        __('Shown in the browser tab on both surfaces and in the admin panel. PNG only, square.'),
                        __('Shown in the browser tab and in the admin panel. PNG only, square.'),
                    ).' '.__('It is the home-screen icon too: upload at least 512×512, or the built-in icon is kept at the sizes it cannot fill. Transparent areas are filled with white.'),
                    FileUpload::make(SnsSettingKey::BrandFaviconFile->value)
                        ->label(__('Upload a new favicon'))
                        ->image()
                        // PNG only: App\Http\Controllers\PublicFileController serves .ico / .svg as an
                        // attachment, which a <link rel="icon"> cannot use.
                        ->acceptedFileTypes(['image/png'])
                        ->maxSize(1024)
                        ->rules(['dimensions:ratio=1,max_width='.self::maxDimension().',max_height='.self::maxDimension()])
                        ->storeFiles(false),
                    'remove_brand_favicon',
                    __('Remove the current favicon'),
                ),
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
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')
                        ->label(__('Save'))
                        ->submit('save')
                        ->keyBindings(['mod+s']),
                ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $brandColor = $data[SnsSettingKey::BrandColor->value] ?? '';

        try {
            app(SaveBrandingSettings::class)(
                is_string($brandColor) ? trim($brandColor) : '',
                $this->fileIntents($data),
            );
        } catch (ImageMetadataStripException) {
            // Don't 500 the panel on a fail-closed strip: notify and halt the save.
            Notification::make()->danger()->title(ImageMetadataStripException::userMessage())->send();

            throw new Halt;
        }

        Notification::make()
            ->success()
            ->title(__('Saved'))
            ->send();

        $this->form->fill($this->currentValues());
    }

    /**
     * Per file setting: the upload that replaces it, null to clear it, or no entry at all to keep the
     * stored file — the upload field is empty on a plain save, which must not wipe the current image.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, UploadedFile|null>
     */
    private function fileIntents(array $data): array
    {
        $intents = [];

        foreach ([
            SnsSettingKey::BrandLogoFile->value => 'remove_brand_logo',
            SnsSettingKey::BrandFaviconFile->value => 'remove_brand_favicon',
        ] as $key => $removeField) {
            $upload = FormUpload::single($data[$key] ?? null);

            if ($upload !== null) {
                $intents[$key] = $upload;
            } elseif ($data[$removeField] ?? false) {
                $intents[$key] = null;
            }
        }

        return $intents;
    }

    /**
     * The upload fields are deliberately absent: they never reload the stored bytes, and a filled
     * field would read as "this is what will be saved" when leaving it blank is what keeps the file.
     *
     * @return array<string, mixed>
     */
    private function currentValues(): array
    {
        return [
            SnsSettingKey::BrandColor->value => (string) app(SnsSettingService::class)->get(SnsSettingKey::BrandColor),
            'remove_brand_logo' => false,
            'remove_brand_favicon' => false,
        ];
    }

    /**
     * One "current image + replace + remove" block. $currentUrl is resolved at render, not at build:
     * the same request that saves has already built this schema, and the preview has to show what was
     * just stored. The removal toggle exists only while there is a file to remove.
     *
     * @param  Closure(): ?string  $currentUrl
     */
    private function fileSection(SnsSettingKey $key, Closure $currentUrl, string $description, FileUpload $upload, string $removeField, string $removeLabel): Section
    {
        return Section::make($key->label())
            ->description($description)
            ->schema([
                Image::make(fn (): string => $currentUrl() ?? '', $key->label())
                    ->imageHeight('96px')
                    ->visible(fn (): bool => $currentUrl() !== null),

                $upload->helperText(fn (): ?string => $currentUrl() === null ? null : __('Upload a new image to replace the current one.')),

                Toggle::make($removeField)
                    ->label($removeLabel)
                    ->visible(fn (): bool => $currentUrl() !== null),
            ]);
    }

    private static function maxDimension(): int
    {
        return (int) config('openpne.images.max_upload_dimension', 5000);
    }

    /**
     * Surface-scope wording. With Classic available the copy must label which surface a setting
     * affects (docs/internals/classic-compatibility.md); on a modern_only install the operator never
     * sees Classic, so the copy must not mention surfaces at all.
     */
    private static function surfaceScoped(string $withClassic, string $modernOnly): string
    {
        return SurfaceResolver::classicAvailable() ? $withClassic : $modernOnly;
    }
}
