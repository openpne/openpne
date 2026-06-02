<?php

namespace App\Models;

use Database\Factories\ProfileOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A choice for a custom select/radio/checkbox profile field. Labels are localised in
 * `profile_option_translations` keyed by (id, lang). Preset fields do not use this table.
 */
class ProfileOption extends Model
{
    /** @use HasFactory<ProfileOptionFactory> */
    use HasFactory;

    protected $fillable = ['profile_id', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'profile_id');
    }

    /** @return HasMany<ProfileOptionTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(ProfileOptionTranslation::class, 'id', 'id');
    }

    public function getLabel(string $lang = 'ja_JP'): string
    {
        return (string) ($this->translations->firstWhere('lang', $lang)?->value ?? '');
    }

    public function setLabel(string $lang, string $value): void
    {
        DB::table('profile_option_translations')->updateOrInsert(
            ['id' => $this->getKey(), 'lang' => $lang],
            ['value' => $value],
        );
    }
}
