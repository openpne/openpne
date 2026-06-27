<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Forms\Components\BannerImagePicker;
use App\Filament\Resources\BannerImages\BannerImageResource;
use App\Models\Banner;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * The Classic #topBanner config, one screen per placement (OpenPNE 3 design/banner). Each placement
 * either shows images picked from the shared pool (default, one at random per request) or operator
 * HTML (is_use_html, emitted raw — admin-trusted). The images themselves are uploaded and edited in
 * the Banner images resource; here you choose which of them each placement shows.
 *
 * @property-read Schema $form
 */
class BannerSettings extends Page
{
    protected static ?int $navigationSort = 4;

    /** OpenPNE 3 PC banner placements (top only; see BannerSeeder). */
    private const PLACEMENTS = ['top_before', 'top_after'];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedMegaphone;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Appearance (Classic)');
    }

    public static function getNavigationLabel(): string
    {
        return __('Banner');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Banner');
    }

    public function mount(): void
    {
        $this->form->fill($this->currentValues());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components($this->buildSections())
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

        foreach (self::PLACEMENTS as $name) {
            $banner = Banner::updateOrCreate(
                ['name' => $name],
                [
                    'is_use_html' => ($data[$name.'_mode'] ?? 'images') === 'html',
                    'html' => ($data[$name.'_html'] ?? '') !== '' ? $data[$name.'_html'] : null,
                ],
            );

            // Keep the image selection only while the placement is in image mode; switching to HTML
            // leaves it untouched so it survives a round-trip back to images (OpenPNE 3 parity).
            if (($data[$name.'_mode'] ?? 'images') === 'images') {
                $banner->images()->sync(array_map('intval', $data[$name.'_images'] ?? []));
            }
        }

        Notification::make()
            ->success()
            ->title(__('Saved'))
            ->send();

        $this->form->fill($this->currentValues());
    }

    /**
     * @return array<string, mixed>
     */
    private function currentValues(): array
    {
        $values = [];
        foreach (self::PLACEMENTS as $name) {
            $banner = Banner::firstOrCreate(['name' => $name]);
            $values[$name.'_mode'] = $banner->is_use_html ? 'html' : 'images';
            $values[$name.'_html'] = (string) $banner->html;
            // Strings so the picker's Alpine checkbox x-model matches the option values (which are strings).
            $values[$name.'_images'] = $banner->images()->pluck('banner_images.id')->map(fn (int $id): string => (string) $id)->all();
        }

        return $values;
    }

    /**
     * One section per placement: the mode, the pool images shown when in image mode, and the HTML used
     * when in HTML mode.
     *
     * @return list<Section>
     */
    private function buildSections(): array
    {
        $sections = [];
        foreach (self::PLACEMENTS as $name) {
            $sections[] = Section::make(BannerImageResource::placementLabel($name))->schema([
                Radio::make($name.'_mode')
                    ->label(__('Display mode'))
                    ->options([
                        'images' => __('Show banner images'),
                        'html' => __('Use custom HTML'),
                    ])
                    ->descriptions([
                        'images' => __('Show one of the selected images at random.'),
                        'html' => __('Show your own HTML instead of images.'),
                    ])
                    ->default('images')
                    ->live(),

                BannerImagePicker::make($name.'_images')
                    ->label(__('Images shown here'))
                    ->helperText(new HtmlString(sprintf(
                        '<a href="%s" style="text-decoration:underline">%s</a>',
                        e(BannerImageResource::getUrl('index')),
                        e(__('Add or manage banner images')),
                    )))
                    ->visible(fn (Get $get): bool => $get($name.'_mode') === 'images'),

                Textarea::make($name.'_html')
                    ->label(__('HTML'))
                    ->rows(4)
                    ->visible(fn (Get $get): bool => $get($name.'_mode') === 'html'),
            ]);
        }

        return $sections;
    }
}
