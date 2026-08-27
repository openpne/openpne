<?php

namespace App\Models;

use Database\Factories\HomeIssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// One published front page. `window_start` (exclusive) to `published_at` (inclusive) is the span it
// drew from; the next issue picks its own lower bound up from `published_at`.
#[Fillable(['number', 'issue_date', 'window_start', 'published_at'])]
class HomeIssue extends Model
{
    /** @use HasFactory<HomeIssueFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'issue_date' => 'date',
            'window_start' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * The ledger for this issue. Grouped by section and in rank order within one, so a caller may
     * split the rows without sorting them; which section leads the page is the page's own business.
     *
     * @return HasMany<HomeIssueItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(HomeIssueItem::class)->orderBy('section')->orderBy('rank');
    }
}
