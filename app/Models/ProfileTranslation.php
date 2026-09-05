<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The OpenPNE 3 Doctrine I18n shape: the key is (id, lang) and `id` is not an autoincrement, so a
 * write is an upsert.
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
