<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * See docs/internals/upgrade.md, "Checkpoints and resume".
 */
class UpgradeState extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'openpne4_upgrade_state';

    protected $guarded = [];

    protected $casts = [
        'rows_affected' => 'integer',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
