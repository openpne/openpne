<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Localised caption for a Navigation item, keyed by (id, lang) — the OpenPNE 3 Doctrine I18n
 * table shape. `id` is the navigation id (not auto-incrementing), so reads go through the
 * Navigation::translations relation and writes use updateOrInsert (composite key).
 */
class NavigationTranslation extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = ['id', 'caption', 'lang'];

    /** @return BelongsTo<Navigation, $this> */
    public function navigation(): BelongsTo
    {
        return $this->belongsTo(Navigation::class, 'id', 'id');
    }
}
