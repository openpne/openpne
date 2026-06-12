<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * An admin-configurable Classic navigation item (OpenPNE 3 `navigation`).
 *
 * The DOM `<li>` id is derived from `source_uri` (the original OpenPNE 3 uri) when present, so a
 * site's custom CSS keeps matching after the upgrade normalizes uri to a URL; admin-created rows
 * (source_uri null) derive it from uri. Captions are localised in `navigation_translations` keyed
 * by (id, lang) and may contain `%term%` placeholders the renderer resolves via TermService.
 */
class Navigation extends Model
{
    /** PC navigation contexts. Mobile/smartphone/backend types are out of the Classic scope. */
    public const TYPES = ['insecure_global', 'secure_global', 'default', 'friend', 'community'];

    /** Global-nav contexts share the OpenPNE 3 `globalNav_` id prefix; local-nav uses the type. */
    public const GLOBAL_TYPES = ['insecure_global', 'secure_global'];

    /** Translation lang code (OpenPNE/Doctrine I18n) for each app locale. */
    private const TRANSLATION_LANG = ['ja' => 'ja_JP', 'en' => 'en'];

    protected $fillable = ['type', 'uri', 'source_uri', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** @return HasMany<NavigationTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(NavigationTranslation::class, 'id', 'id');
    }

    /**
     * OpenPNE 3's id slug for the rendered `<li>` (op_url_to_id over the original uri, plus `:` for
     * the `:id` placeholder OpenPNE 4 introduces). The renderer prefixes `globalNav_` or the type.
     */
    public function domSlug(): string
    {
        return self::slug($this->source_uri ?? $this->uri);
    }

    public static function slug(string $uri): string
    {
        return str_replace(
            ['/', ',', ';', '~', '?', '@', '&', '=', '+', '$', '%', '#', '!', '(', ')', ':'],
            '_',
            $uri,
        );
    }

    public function getCaption(string $lang = 'ja_JP'): string
    {
        return $this->translations->firstWhere('lang', $lang)?->caption ?? '';
    }

    /** Upsert this item's localised caption for a translation lang. */
    public function setTranslation(string $lang, string $caption): void
    {
        DB::table('navigation_translations')->updateOrInsert(
            ['id' => $this->getKey(), 'lang' => $lang],
            ['caption' => $caption],
        );
    }

    public static function translationLang(string $locale): string
    {
        return self::TRANSLATION_LANG[$locale] ?? 'en';
    }
}
