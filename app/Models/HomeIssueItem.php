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
 * `source_type` is a morph alias and is never written as a literal, so a rename stays a morphMap
 * edit. The row outlives its source on purpose, and a caller that cannot resolve one drops the item
 * rather than treating it as an error.
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
