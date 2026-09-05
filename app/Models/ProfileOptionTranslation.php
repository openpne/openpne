<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The OpenPNE 3 Doctrine I18n shape: the key is (id, lang) and `id` is not an autoincrement, so a
 * write is an upsert.
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
