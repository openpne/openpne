<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A name/value setting for one Gadget (OpenPNE 3 `gadget_config`). */
class GadgetConfig extends Model
{
    public $timestamps = false;

    protected $fillable = ['gadget_id', 'name', 'value'];

    /** @return BelongsTo<Gadget, $this> */
    public function gadget(): BelongsTo
    {
        return $this->belongsTo(Gadget::class);
    }
}
