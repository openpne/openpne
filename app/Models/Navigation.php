<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * OpenPNE 3 `navigation`. The DOM `<li>` id comes from `source_uri` when present, so a site's custom
 * CSS keeps matching after the upgrade normalizes `uri` to a URL.
 */
class Navigation extends Model
{
    /** Mobile, smartphone and backend types are out of Classic's scope. */
    public const TYPES = ['insecure_global', 'secure_global', 'default', 'friend', 'group'];

    /** Global-nav contexts share the OpenPNE 3 `globalNav_` id prefix; local-nav uses the type. */
    public const GLOBAL_TYPES = ['insecure_global', 'secure_global'];

    /**
     * `group` renders as `community`: a site's custom CSS matches on the OpenPNE 3 word, so the
     * storage rename must not reach the DOM.
     */
    private const PRESENTATION_TOKENS = ['group' => 'community'];

    public static function presentationToken(string $type): string
    {
        return self::PRESENTATION_TOKENS[$type] ?? $type;
    }

    /** The lang codes OpenPNE 3's Doctrine I18n tables use. */
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
     * OpenPNE 3's `op_url_to_id` over the original uri, plus `:` for the `:id` placeholder OpenPNE 4
     * introduces.
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
