<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * Shared shape for the OpenPNE 3 `activity_image` join rows: the file-backed images of the activities
 * that land in one target, numbered 1..N by id (OpenPNE 3 has no slot column).
 */
abstract class ActivityImageUpgrade extends UpgradeStep
{
    protected string $source = 'activity_image';

    /** The target's column naming the parent record. */
    abstract protected function parentColumn(): string;

    /** SQL boolean over the alias `activity_data`: the parent activity lands in this step's target. */
    abstract protected function landing(): string;

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            $this->parentColumn() => Column::source('activity_data_id'),
            'file_id' => Column::source('file_id'),
            'number' => Column::expr(ActivityThread::imageNumber(), uses: ['activity_data_id', 'id', 'file_id']),
        ];
    }

    public function filter(): ?string
    {
        return '`activity_image`.`file_id` IS NOT NULL AND '.ActivityThread::imageOf($this->landing());
    }

    public function filterColumns(): array
    {
        return ['file_id', 'activity_data_id'];
    }

    public function gaps(): array
    {
        return [
            'mime_type' => 'files.type carries the MIME type (FileUpgrade).',
            'uri' => 'An image held only as a URL (no file row) has no OpenPNE 4 representation; not migrated (ActivityPreflight counts them).',
            'created_at' => 'The join row has no timestamps (the File carries them).',
            'updated_at' => 'The join row has no timestamps (the File carries them).',
        ];
    }
}
