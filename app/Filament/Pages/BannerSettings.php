<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\HiddenWhenModernOnly;
use App\Filament\Forms\Components\BannerImagePicker;
use App\Filament\Resources\BannerImages\BannerImageResource;
use App\Models\Banner;
use App\Models\BannerImage;
use App\Support\OperatorContent;
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
 * The custom-content mode edits `html` or `markdown`, following App\Support\OperatorContent::rawHtml().
 *
 * @property-read Schema $form
 */
class BannerSettings extends Page
{
    use HiddenWhenModernOnly;

    protected static ?int $navigationSort = 4;

    /** OpenPNE 3 PC banner placements: top (#topBanner) and side (the sideBanner gadget), by login state. */
    private const PLACEMENTS = ['top_before', 'top_after', 'side_before', 'side_after'];

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
            $mode = $data[$name.'_mode'] ?? 'images';

            $banner = Banner::firstOrCreate(['name' => $name]);
            $banner->is_use_html = $mode === 'html';
            // Each mode writes only its own payload: OpenPNE 3 keeps both banner.html and the image
            // selection regardless of is_use_html, and the hidden field is not dehydrated, so writing
            // it would wipe it.
            if ($mode === 'html' && OperatorContent::rawHtml()) {
                $banner->html = ($data[$name.'_html'] ?? '') !== '' ? $data[$name.'_html'] : null;
            }
            if ($mode === 'html' && ! OperatorContent::rawHtml()) {
                // Never NULL: that reads as "not converted yet", and a later conversion would bring the old HTML back.
                $banner->markdown = (string) ($data[$name.'_markdown'] ?? '');
            }
            $banner->save();

            if ($mode === 'images') {
                // Drop ids whose image was deleted since the page loaded, so a stale selection can't
                // hit a banner_use_images FK violation.
                $selected = BannerImage::whereKey(array_map('intval', $data[$name.'_images'] ?? []))->pluck('id')->all();
                $banner->images()->sync($selected);
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
            $values[$name.'_markdown'] = (string) $banner->markdown;
            // Strings so the picker's Alpine checkbox x-model matches the option values (which are strings).
            $values[$name.'_images'] = $banner->images()->pluck('banner_images.id')->map(fn (int $id): string => (string) $id)->all();
        }

        return $values;
    }

    /**
     * @return list<Section>
     */
    private function buildSections(): array
    {
        $raw = OperatorContent::rawHtml();
        $sections = [];
        foreach (self::PLACEMENTS as $name) {
            $sections[] = Section::make(BannerImageResource::placementLabel($name))->schema([
                Radio::make($name.'_mode')
                    ->label(__('Display mode'))
                    ->options([
                        'images' => __('Show banner images'),
                        'html' => $raw ? __('Use custom HTML') : __('Use custom text'),
                    ])
                    ->descriptions([
                        'images' => __('Show one of the selected images at random.'),
                        'html' => $raw ? __('Show your own HTML instead of images.') : __('Show your own text instead of images.'),
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
                    ->label($raw ? __('HTML') : __('Previous HTML'))
                    ->helperText($raw ? null : __('HTML stored before this site used Markdown. It is not shown on the site.'))
                    ->rows(4)
                    // Disabled outside raw mode, so it is never dehydrated and a save cannot write the column.
                    ->disabled(! $raw)
                    ->visible(fn (Get $get): bool => $get($name.'_mode') === 'html' && ($raw || (string) $get($name.'_html') !== '')),

                Textarea::make($name.'_markdown')
                    ->label(__('Text'))
                    ->helperText(__('Markdown is available.'))
                    ->rows(4)
                    ->visible(fn (Get $get): bool => ! $raw && $get($name.'_mode') === 'html'),
            ]);
        }

        return $sections;
    }
}
