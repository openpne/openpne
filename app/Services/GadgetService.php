<?php

declare(strict_types=1);

namespace App\Services;

use App\Gadgets\GadgetKind;
use App\Gadgets\GadgetKindRegistry;
use App\Gadgets\GadgetLayout;
use App\Models\Member;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves the gadgets (`gadgets` + `gadget_configs`) into render-ready items grouped by zone. Rows
 * are cached as plain arrays (the production cache cannot serialize models); the admin UI calls
 * clearCache() after a change. A row is dropped when its kind is unregistered/unavailable, its zone
 * is not in the active layout, or a guest may not see a members-only kind here.
 */
class GadgetService
{
    private const CACHE_KEY = 'gadgets';

    private const CACHE_TTL = 3600;

    public function __construct(private readonly SnsSettingService $settings) {}

    /**
     * Render-ready gadgets for a context, grouped by the active layout's zones (all present, in order).
     * $subject is passed through on each item for the per-member kinds; its meaning is per context
     * (home=viewer, profile=owner, login/sidebanner=null). $viewer is null for a guest.
     *
     * @return array<string, list<array{name: string, component: string, config: array<string, mixed>, partId: ?string, subject: ?Member}>>
     */
    public function zones(string $context, ?Member $subject = null, ?Member $viewer = null): array
    {
        $isMember = $viewer !== null;

        $zones = [];
        foreach (GadgetLayout::zones($this->activeLayout($context)) as $zone) {
            $zones[$zone] = [];
        }

        foreach ($this->grouped()[$context] ?? [] as $row) {
            if (! array_key_exists($row['zone'], $zones)) {
                continue; // a zone the active layout does not expose
            }

            $kind = GadgetKindRegistry::find($row['name']);
            if ($kind === null || ! $kind->isAvailable()) {
                continue;
            }
            if (! $isMember && $kind->viewablePrivilege($context) !== GadgetKind::ANYONE) {
                continue;
            }

            $config = [];
            foreach ($kind->configFields($context) as $field) {
                $config[$field->name] = $field->value($row['config'][$field->name] ?? null);
            }

            $zones[$row['zone']][] = [
                'name' => $kind->name(),
                'component' => $kind->component(),
                'config' => $config,
                'partId' => $kind->partId($row['id']),
                'subject' => $subject,
            ];
        }

        return $zones;
    }

    /** Drop the cached gadget rows. Call after persisting changes from the admin UI. */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** The active layout: a selectable context reads its stored setting; a fixed context its default. */
    private function activeLayout(string $context): string
    {
        $key = GadgetLayout::layoutSettingKey($context);

        return $key !== null ? (string) $this->settings->get($key) : GadgetLayout::defaultLayout($context);
    }

    /**
     * Gadget rows grouped by context as plain arrays (each with its config name=>value map), ordered
     * by sort_order. Cached; guards against the table not existing yet (pre-migrate / console boot).
     *
     * @return array<string, list<array{id: int, zone: string, name: string, source_type: ?string, sort_order: ?int, config: array<string, string>}>>
     */
    private function grouped(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            if (! Schema::hasTable('gadgets')) {
                return [];
            }

            $configs = DB::table('gadget_configs')
                ->get()
                ->groupBy('gadget_id')
                ->map(fn ($rows) => $rows->pluck('value', 'name')->all());

            return DB::table('gadgets')
                ->orderByRaw('sort_order IS NULL, sort_order')
                ->get()
                ->groupBy('context')
                ->map(fn ($rows) => $rows->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'zone' => $row->zone,
                    'name' => $row->name,
                    'source_type' => $row->source_type,
                    'sort_order' => $row->sort_order === null ? null : (int) $row->sort_order,
                    'config' => $configs[$row->id] ?? [],
                ])->values()->all())
                ->all();
        });
    }
}
