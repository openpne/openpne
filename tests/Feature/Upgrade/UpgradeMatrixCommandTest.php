<?php

namespace Tests\Feature\Upgrade;

use Tests\TestCase;

class UpgradeMatrixCommandTest extends TestCase
{
    public function test_renders_targets_filters_and_gaps(): void
    {
        $this->artisan('openpne:upgrade-matrix')
            ->assertSuccessful()
            ->expectsOutputToContain('member_relationship')
            // The filter is what splits one source table across targets — it must be visible.
            ->expectsOutputToContain('Filter: `is_friend = 1`')
            ->expectsOutputToContain('Filter: `is_friend_pre = 1`')
            ->expectsOutputToContain('Filter: `is_access_block = 1`')
            // Member credentials are now sourced from member_config (one unique token per line:
            // expectsOutputToContain matches each line to a single expectation, so overlapping
            // substrings on the same row would starve each other).
            ->expectsOutputToContain("'pc_address'") // email row's member_config subquery
            ->expectsOutputToContain('`password`')   // password is a mapped column, not pending
            ->expectsOutputToContain('Accepted gaps:')
            // Source tables not driven by a standalone step (deferred or flattened) must stay visible too.
            ->expectsOutputToContain('Deferred / flattened source tables')
            ->expectsOutputToContain('`file_bin`');
    }

    public function test_renders_community_config_coverage(): void
    {
        // community_config is read by subquery (not a step), so its per-name coverage and the
        // is_pre split that decomposes community_member must be visible in the matrix.
        $this->artisan('openpne:upgrade-matrix')
            ->assertSuccessful()
            ->expectsOutputToContain('`community_config` name coverage')
            ->expectsOutputToContain('Filter: `is_pre = 0`')
            ->expectsOutputToContain('Filter: `is_pre = 1`')
            ->expectsOutputToContain('`register_policy`')
            // Stock topic-plugin config names stay accounted-for, not flagged as custom configs.
            ->expectsOutputToContain('`public_flag`')
            ->expectsOutputToContain('`topic_authority`')
            ->expectsOutputToContain('`community_member_position`');
    }

    public function test_renders_notification_mail_coverage(): void
    {
        // notification_mail is stepped, but its name filter only carries the templates OpenPNE 4 sends, so
        // the step + the per-name disposition (why each other name is dropped) must both be visible.
        $this->artisan('openpne:upgrade-matrix')
            ->assertSuccessful()
            ->expectsOutputToContain('`notification_mail` → `mail_templates`')
            ->expectsOutputToContain('`notification_mail` name coverage')
            ->expectsOutputToContain('`pc_requestRegisterURL`')
            ->expectsOutputToContain('`pc_reissuedPassword`')
            ->expectsOutputToContain('`mobile_*`');
    }

    public function test_renders_community_topic_steps_and_deferred_images(): void
    {
        // The topic board steps must appear (no silent drop) and the image tables they do not
        // migrate must stay visible in the deferred section, not vanish.
        $this->artisan('openpne:upgrade-matrix')
            ->assertSuccessful()
            ->expectsOutputToContain('`community_topic` → `community_topics`')
            ->expectsOutputToContain('`community_topic_comment` → `community_topic_comments`')
            ->expectsOutputToContain('`community_topic_image`')
            ->expectsOutputToContain('`community_topic_comment_image`');
    }

    public function test_renders_community_event_steps_and_deferred_images(): void
    {
        // The event board, comment and RSVP-pivot steps must appear (no silent drop) and the event
        // image tables they do not migrate must stay visible in the deferred section.
        $this->artisan('openpne:upgrade-matrix')
            ->assertSuccessful()
            ->expectsOutputToContain('`community_event` → `community_events`')
            ->expectsOutputToContain('`community_event_comment` → `community_event_comments`')
            ->expectsOutputToContain('`community_event_member` → `community_event_members`')
            ->expectsOutputToContain('`community_event_image`')
            ->expectsOutputToContain('`community_event_comment_image`');
    }
}
