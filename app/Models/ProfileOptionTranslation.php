<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Localised label for a ProfileOption, keyed by (id, lang). See ProfileTranslation for the
 * composite-key handling.
 */
class ProfileOptionTranslation extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = ['id', 'value', 'lang'];

    /** @return BelongsTo<ProfileOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(ProfileOption::class, 'id', 'id');
    }
}
