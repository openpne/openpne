<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\BannerImages\BannerImageResource;
use App\Models\Banner;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
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

/**
 * Per-placement banner mode for the Classic #topBanner: each placement shows either its pool images
 * (default) or operator HTML (is_use_html). The HTML is emitted raw in the Classic shell (OpenPNE 3
 * parity, admin-trusted); the images themselves are managed in the Banner images resource.
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
        return __('Banner settings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Banner settings');
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
            Banner::updateOrCreate(
                ['name' => $name],
                [
                    'is_use_html' => (bool) ($data[$name.'_use_html'] ?? false),
                    'html' => ($data[$name.'_html'] ?? '') !== '' ? $data[$name.'_html'] : null,
                ],
            );
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
            $banner = Banner::where('name', $name)->first();
            $values[$name.'_use_html'] = (bool) $banner?->is_use_html;
            $values[$name.'_html'] = (string) $banner?->html;
        }

        return $values;
    }

    /**
     * One section per placement: a mode toggle and the HTML used when it is on.
     *
     * @return list<Section>
     */
    private function buildSections(): array
    {
        $sections = [];
        foreach (self::PLACEMENTS as $name) {
            $sections[] = Section::make(BannerImageResource::placementLabel($name))->schema([
                Toggle::make($name.'_use_html')
                    ->label(__('Use HTML instead of images')),
                Textarea::make($name.'_html')
                    ->label(__('HTML'))
                    ->rows(4),
            ]);
        }

        return $sections;
    }
}
