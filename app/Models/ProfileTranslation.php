<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Localised caption/info for a Profile, keyed by (id, lang) — the OpenPNE 3 Doctrine I18n
 * table shape. `id` is the profile id (not auto-incrementing), so reads go through the
 * Profile::translations relation and writes use updateOrInsert (composite key).
 */
class ProfileTranslation extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = ['id', 'caption', 'info', 'lang'];

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'id', 'id');
    }
}
