<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `community_event` (opCommunityTopicPlugin) → OpenPNE 4 `community_events`.
 *
 * id is preserved because community_event_comment, community_event_member and community_event_image
 * reference community_event.id; keeping it lets the comment / RSVP / (deferred) image upgrades rewire
 * by id. member_id stays nullable: a withdrawn author is NULL in OpenPNE 3 (onDelete set null) and the
 * event is kept. name/body/open_date_comment/area are TEXT → TEXT, so long content round-trips
 * untruncated. open_date / application_deadline / capacity carry the scheduling data verbatim, and
 * updated_at is the board sort key (the event board orders by it, not by open_date). event_updated_at
 * is the original activity timestamp, kept for fidelity though no OpenPNE 4 widget reads it yet.
 *
 * community_event_image is not migrated here — it is recorded in StepRegistry::deferredSourceTables()
 * (binary migration pending the `file` step, like the other image tables).
 */
class CommunityEventUpgrade extends UpgradeStep
{
    protected string $source = 'community_event';

    protected string $target = 'community_events';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'community_id' => Column::source('community_id'),
            'member_id' => Column::source('member_id'),
            'name' => Column::source('name'),
            'body' => Column::source('body'),
            'event_updated_at' => Column::source('event_updated_at'),
            'open_date' => Column::source('open_date'),
            'open_date_comment' => Column::source('open_date_comment'),
            'area' => Column::source('area'),
            'application_deadline' => Column::source('application_deadline'),
            'capacity' => Column::source('capacity'),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }
}
