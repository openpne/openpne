<?php

namespace App\Upgrade\Diary;

use App\Features\Diary\Visibility;
use App\Models\Diary;
use UnexpectedValueException;

/**
 * Maps an OpenPNE 3 `diary` row onto the OpenPNE 4 `diaries` schema.
 *
 * Upgrade-only logic lives here, not on the runtime Visibility enum or Diary model:
 * the legacy public_flag/is_open encoding is a migration concern, not a runtime one.
 *
 * Fidelity invariants (see diary-upgrade-ledger.md):
 * - the original id is preserved, so deferred diary_comment/image migration FKs line up;
 * - created_at/updated_at are preserved, never replaced with the upgrade run's clock;
 * - TEXT title/body round-trip without truncation.
 */
class DiaryUpgradeMapper
{
    /**
     * OpenPNE 3 public_flag + is_open → OpenPNE 4 Visibility.
     *
     * Open (web-public) is an OpenPNE 4 value derived at upgrade time, not a stored
     * public_flag: OpenPNE 3 normalises web-public as public_flag=1 + is_open=1 (older
     * data may carry the legacy PUBLIC_FLAG_OPEN=4). is_open only promotes the
     * SNS-public level; on friend/private rows it is an anomaly and the restrictive
     * level wins. Unknown flags throw rather than silently widening exposure.
     */
    public static function mapVisibility(int $publicFlag, bool $isOpen): Visibility
    {
        return match ($publicFlag) {
            1 => $isOpen ? Visibility::Open : Visibility::Members,
            2 => Visibility::Friends,
            3 => Visibility::Private,
            4 => Visibility::Open,
            default => throw new UnexpectedValueException("Unmappable OpenPNE 3 diary.public_flag: {$publicFlag}"),
        };
    }

    /**
     * Target `diaries` attributes for a legacy row. visibility is the int column value
     * so the result is usable for both Eloquent and bulk insert paths.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(LegacyDiary $row): array
    {
        return [
            'id' => $row->id,
            'member_id' => $row->member_id,
            'title' => $row->title,
            'body' => $row->body,
            'visibility' => self::mapVisibility($row->public_flag, $row->is_open)->value,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /** Persist a legacy row into `diaries`, preserving id and timestamps. */
    public function store(LegacyDiary $row): Diary
    {
        $diary = new Diary;
        $diary->timestamps = false;
        $diary->forceFill($this->toAttributes($row));
        $diary->save();

        return $diary;
    }
}
