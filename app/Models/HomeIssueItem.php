<?php

namespace App\Models;

use App\Features\Home\HomeIssueSection;
use Database\Factories\HomeIssueItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of an issue's ledger: what was featured, where, and how highly.
 *
 * `source_type` is a morph alias and is never written as a literal — a write takes it from the
 * model's getMorphClass(), so a rename stays a morphMap edit. source() may resolve to nothing: the
 * row outlives its source on purpose (see the migration), and a caller that cannot resolve one drops
 * the item rather than treating it as an error.
 */
#[Fillable(['home_issue_id', 'section', 'rank', 'source_type', 'source_id', 'score', 'stats'])]
class HomeIssueItem extends Model
{
    /** @use HasFactory<HomeIssueItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'section' => HomeIssueSection::class,
            'rank' => 'integer',
            'source_id' => 'integer',
            'score' => 'integer',
            'stats' => 'array',
        ];
    }

    /** @return BelongsTo<HomeIssue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(HomeIssue::class, 'home_issue_id');
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo('source', 'source_type', 'source_id');
    }
}
