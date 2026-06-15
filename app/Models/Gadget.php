<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An admin-configurable Classic gadget (OpenPNE 3 `gadget`).
 *
 * `context` + `zone` is the placement (the OpenPNE 3 `type` split in two; the original is kept in
 * `source_type` for custom-CSS compatibility). `name` is the gadget kind resolved via
 * App\Gadgets\GadgetKindRegistry — an unregistered name renders nothing. Per-gadget settings are in
 * `gadget_configs`, read through config().
 */
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

    /** The stored value of a config parameter, or null when unset (the kind's default then applies). */
    public function config(string $name): ?string
    {
        return $this->configs->firstWhere('name', $name)?->value;
    }
}
