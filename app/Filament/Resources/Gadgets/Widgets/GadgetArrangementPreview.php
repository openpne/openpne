<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gadgets\Widgets;

use App\Filament\Resources\Gadgets\GadgetResource;
use App\Gadgets\GadgetKindRegistry;
use App\Models\Gadget;
use App\Models\Member;
use App\Services\GadgetService;
use Filament\Widgets\Widget;
use Livewire\Attributes\On;

/**
 * Read-only "what each page will look like" panel above the gadget table: every context (home / profile
 * / login / side banner) is drawn as a layout mock with the gadgets that render shown as chips in their
 * zone. It renders through GadgetService::zones() with the (subject, viewer) each surface really uses, so
 * the preview never shows a chip the Classic page would drop; rows that are configured but not rendered
 * (wrong zone / unsupported / unavailable) are summarised as a count pointing at the table. It reads only
 * names and zones (never the gadget Blade), so an unsaved representative member is safe.
 */
class GadgetArrangementPreview extends Widget
{
    protected string $view = 'filament.resources.gadgets.widgets.gadget-arrangement-preview';

    protected int|string|array $columnSpan = 'full';

    /** An in-page reorder dispatches this; receiving it re-renders the preview. */
    #[On('gadgets-arranged')]
    public function refresh(): void {}

    /**
     * @return list<array{key: string, label: string, letter: string, zones: list<array{key: string, label: string, chips: list<string>}>, notShown: int}>
     */
    public function previews(): array
    {
        $service = app(GadgetService::class);
        $member = new Member; // representative logged-in member: only flips the guest filter, no DB row
        $configured = Gadget::query()->selectRaw('context, count(*) as total')->groupBy('context')->pluck('total', 'context');

        $previews = [];
        foreach (GadgetResource::contextOptions() as $context => $label) {
            // The (subject, viewer) each surface really renders with, so the preview cannot diverge.
            [$subject, $viewer] = match ($context) {
                'home', 'profile' => [$member, $member],
                'sidebanner' => [null, $member],
                default => [null, null], // login: logged-out
            };

            $zoneLabels = GadgetResource::zoneOptions($context);
            $rendered = 0;
            $zones = [];
            foreach ($service->zones($context, $subject, $viewer) as $zone => $items) {
                $chips = array_map(
                    static fn (array $item): string => GadgetKindRegistry::find($item['name'])?->label() ?? $item['name'],
                    $items,
                );
                $rendered += count($chips);
                $zones[] = ['key' => $zone, 'label' => $zoneLabels[$zone] ?? $zone, 'chips' => $chips];
            }

            $previews[] = [
                'key' => $context,
                'label' => $label,
                'letter' => $service->layoutLetter($context),
                'zones' => $zones,
                'notShown' => max(0, ((int) ($configured[$context] ?? 0)) - $rendered),
            ];
        }

        return $previews;
    }
}
