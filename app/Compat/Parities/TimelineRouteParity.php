<?php

namespace App\Compat\Parities;

use App\Compat\CompatLevel as L;
use App\Compat\RouteMap;
use App\Compat\RouteParity;
use App\Compat\ScreenElement;
use App\Compat\ScreenStatus as S;

class TimelineRouteParity extends RouteParity
{
    protected string $module = 'timeline';

    public function maps(): array
    {
        return [
            new RouteMap('member_timeline', '/member/:id/timeline', 'timeline.member', 'GET', op3Action: 'member'),
            // OpenPNE 3 reached the single-activity page through the global /:module/:action fallback
            // (/timeline/show/id/:id), so there is no named route — a fallback-only map that still
            // derives the page_timeline_show body id.
            new RouteMap(null, null, 'timeline.show', 'GET', op3Action: 'show'),
        ];
    }

    public function gaps(): array
    {
        return [
            // OpenPNE 3 /sns/timeline: the cross-member home feed, a distinct surface from the
            // per-member timeline.
            'sns_timeline' => 'The cross-member home feed (self + friends + all members).',
            'community_timeline' => 'Community-scoped timeline (foreign_table=community).',
        ];
    }

    /** OpenPNE 3 keeps the global /:module/:action fallback on (no timeline_nodefaults route). */
    public function acknowledgesGlobalFallback(): bool
    {
        return true;
    }

    public function compatRedirects(): array
    {
        // OpenPNE 3's single-post permalink (timeline/show/id/:id, reached via the global
        // fallback and linked from the post timestamp) is redirected to the canonical timeline.show.
        return ['/timeline/show/id/:id' => 'timeline.show'];
    }

    /**
     * Surface elements per OpenPNE 3 timeline template (templates/_timelineProfile.php +
     * _timelineTemplate.php + showSuccess.php). OpenPNE 3 streams activities client-side from the
     * API via jQuery templates; the Classic adapter renders them server-side, so the rendering
     * mechanism differs (an L3 may-differ) while the content is preserved. Write-side and reply
     * elements are not part of this read surface.
     */
    public function screens(): array
    {
        return [
            // memberSuccess.php → timelineProfile component → timeline/member.blade.php
            'member' => [
                new ScreenElement('author nickname + profile link', L::Two, S::Ported, 'timelineTemplate <a href="${member.profile_url}">${member.name}', 'Classic links the nickname server-side; OpenPNE 3 builds the post client-side from the API'),
                new ScreenElement('screen-name handle', L::Three, S::Deferred, 'timelineTemplate ${member.screen_name}', 'OpenPNE 3 shows the @screen_name handle; Classic shows the nickname'),
                new ScreenElement('activity body', L::Two, S::Partial, 'timelineTemplate {{html body_html}}', 'plain text; display-time URL auto-link / decoration not rendered'),
                new ScreenElement('attached image', L::Three, S::Ported, 'activity_image (opTimeline image) + lightbox.js', 'ActivityImage thumbnail via the shared File; FilePolicy-gated by the activity visibility'),
                new ScreenElement('visibility label', L::Three, S::Ported, 'timelineTemplate public_status friend/private', 'Visibility label shown for every level (OpenPNE 3 labels only friend/private)'),
                new ScreenElement('permalink + datetime', L::Three, S::Ported, 'timelineTemplate timeline/show/id/${id} + jquery.timeago', 'absolute localized datetime linking to timeline.show; OpenPNE 3 renders a relative timeago'),
                new ScreenElement('pagination', L::Two, S::Ported, '_timelineProfile #timeline-loadmore もっと読む', 'server-side pager; OpenPNE 3 is infinite-scroll over the API'),
                new ScreenElement('compose box', L::One, S::Ported, '_timelineProfile #timeline-submit-button', 'standalone /timeline/new compose page linked from the timeline; OpenPNE 3 inlines the form'),
                new ScreenElement('per-post reply form', L::Two, S::Deferred, 'timelineTemplate #timeline-post-comment-form', 'reply threads are not part of the read view'),
                new ScreenElement('own-post delete', L::Two, S::Ported, 'timelineTemplate timeline-post-delete-confirm', 'delete link + confirm page; OpenPNE 3 uses an inline JS confirm'),
            ],
            // showSuccess.php → timeline/show.blade.php
            'show' => [
                new ScreenElement('single activity (author, body, datetime)', L::One, S::Ported, 'showSuccess $activity->getMember()->getName() + timelineTemplate'),
                new ScreenElement('screen-name handle', L::Three, S::Deferred, 'timelineTemplate ${member.screen_name}', 'OpenPNE 3 shows the @screen_name handle; Classic shows the nickname'),
                new ScreenElement('activity body', L::Two, S::Partial, 'timelineTemplate {{html body_html}}', 'plain text; display-time URL auto-link / decoration not rendered'),
                new ScreenElement('attached image', L::Three, S::Ported, 'activity_image (opTimeline image) + lightbox.js', 'ActivityImage thumbnail via the shared File; FilePolicy-gated by the activity visibility'),
                new ScreenElement('visibility label', L::Three, S::Ported, 'timelineTemplate public_status friend/private', 'Visibility label shown for every level (OpenPNE 3 labels only friend/private)'),
                new ScreenElement('reply thread', L::One, S::Deferred, 'showSuccess gorgon timeline-list (commentSearch API)', 'reply threads are not part of the read view'),
                new ScreenElement('own-post delete', L::Two, S::Missing, 'timelineTemplate timeline-post-delete-confirm', 'no delete control on the permalink page; delete is reached from the member timeline'),
            ],
        ];
    }
}
