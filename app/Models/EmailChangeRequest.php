<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * `token` and `cancel_token` hold SHA-256 hashes; the raw tokens exist only in the confirmation and
 * cancel links. One row per member, cascading away with the member.
 *
 * @property int $id
 * @property int $member_id
 * @property string $new_email
 * @property string $token
 * @property string|null $cancel_token
 * @property Carbon|null $created_at
 */
class EmailChangeRequest extends Model
{
    use Prunable;

    public $timestamps = false;

    protected $fillable = ['member_id', 'new_email', 'token', 'cancel_token', 'created_at'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return Builder<EmailChangeRequest>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subMinutes((int) config('openpne.email_change.token_ttl_minutes')));
    }
}
