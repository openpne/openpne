<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An admin-configurable Classic gadget (OpenPNE 3 `gadget`): `context`+`zone` placement, `name` = kind. */
class Gadget extends Model
{
    protected $fillable = ['context', 'zone', 'name', 'source_type', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** @return HasMany<GadgetConfig, $this> */
    public function configs(): HasMany
    {
        return $this->hasMany(GadgetConfig::class);
    }

    /** The stored value of a config parameter, or null when unset. */
    public function config(string $name): ?string
    {
        return $this->configs->firstWhere('name', $name)?->value;
    }
}
