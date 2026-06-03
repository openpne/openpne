<?php

namespace App\Models;

use App\Support\Visibility;
use Database\Factories\DiaryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['member_id', 'title', 'body', 'visibility'])]
class Diary extends Model
{
    /** @use HasFactory<DiaryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'visibility' => Visibility::class,
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return HasMany<DiaryComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(DiaryComment::class);
    }
}
