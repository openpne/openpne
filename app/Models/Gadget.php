<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** OpenPNE 3 `gadget`; `name` holds the gadget kind. */
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

    public function config(string $name): ?string
    {
        return $this->configs->firstWhere('name', $name)?->value;
    }
}
