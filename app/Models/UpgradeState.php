<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A row in openpne4_upgrade_state: the upgrade runner's per-step checkpoint. completed ⟺ the step's
 * copy committed (the runner writes it inside the step's transaction), so a re-run skips it.
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
