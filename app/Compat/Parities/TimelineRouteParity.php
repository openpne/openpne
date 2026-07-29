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
            // The SNS-wide home feed. OpenPNE 3's pc_frontend executeSns forwarded to the error page
            // (the feed ran on mobile and as the homeAllTimeline home gadget); OpenPNE 4 unifies it
            // into a real /timeline page. The /sns/timeline URL is preserved by redirect (below).
            new RouteMap('sns_timeline', '/sns/timeline', 'timeline.index', 'GET', op3Action: 'sns'),
            // OpenPNE 3 reached the single-activity page through the global /:module/:action fallback
            // (/timeline/show/id/:id), so there is no named route — a fallback-only map that still
            // derives the page_timeline_show body id.
            new RouteMap(null, null, 'timeline.show', 'GET', op3Action: 'show'),
        ];
    }

    public function gaps(): array
    {
        return [
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
        return [
            // OpenPNE 3's single-post permalink (timeline/show/id/:id, reached via the global
            // fallback and linked from the post timestamp) → canonical timeline.show.
            '/timeline/show/id/:id' => 'timeline.show',
            // OpenPNE 3's SNS-wide timeline URL → canonical home feed.
            '/sns/timeline' => 'timeline.index',
        ];
    }

    /**
     * Surface elements per OpenPNE 3 timeline template (templates/_timelineAll.php +
     * _timelineProfile.php + _timelineTemplate.php + showSuccess.php). OpenPNE 3 streams activities
     * client-side from the API via jQuery templates; the Classic adapter renders them server-side,
     * so the rendering mechanism differs (an L3 may-differ) while the content is preserved.
     * Write-side and reply elements are not part of this read surface.
     */
    public function screens(): array
    {
        return [
            // The SNS-wide home feed. OpenPNE 3 rendered this as the homeAllTimeline home gadget
            // (_timelineAll.php) sharing _timelineTemplate.php; OpenPNE 4 serves it as the /timeline page.
            'sns' => [
                new ScreenElement('author nickname + profile link', L::Two, S::Ported, 'timelineTemplate <a href="${member.profile_url}">${member.name}', 'cross-member feed; Classic links the nickname server-side, OpenPNE 3 builds each post client-side from the API'),
                new ScreenElement('author avatar (48px, rounded)', L::Two, S::Ported, 'timelineTemplate ${member.profile_image} + timeline.css', 'the 48px thumbnail in timeline-post-member-image, no_image fallback'),
                new ScreenElement('opTimelinePlugin stylesheets', L::Two, S::Partial, '_timelineAll use_stylesheet bootstrap.css + timeline.css (component-driven)', 'pushed by every timeline-rendering screen and gadget (layout pluginCss stack, once per page); lightbox.css and jquery.colorbox.css stay unloaded with their scripts'),
                new ScreenElement('screen-name handle', L::Three, S::Deferred, 'timelineTemplate ${member.screen_name}', 'OpenPNE 3 shows the @screen_name handle; Classic shows the nickname'),
                new ScreenElement('activity body', L::Two, S::Partial, 'timelineTemplate {{html body_html}}', 'plain text; display-time URL auto-link rendered, rich-text decoration not'),
                new ScreenElement('attached image', L::Three, S::Ported, 'activity_image (opTimeline image) + lightbox.js', 'ActivityImage thumbnail via the shared File; FilePolicy-gated by the activity visibility'),
                new ScreenElement('visibility label', L::Three, S::Ported, 'timelineTemplate public_status friend/private', 'the 公開範囲 span for friend/private, as OpenPNE 3 draws it, plus Open — an OpenPNE 4-native audience worth naming; the members-wide default renders no label'),
                new ScreenElement('permalink + datetime', L::Three, S::Ported, 'timelineTemplate timeline/show/id/${id} + jquery.timeago', 'absolute localized datetime linking to timeline.show; OpenPNE 3 renders a relative timeago'),
                new ScreenElement('pagination', L::Two, S::Partial, '_timelineAll #timeline-loadmore もっと読む', 'a server-side pager (Classic pager parts); OpenPNE 3 loads more in place over the API — a future Classic JS-lane candidate'),
                new ScreenElement('compose box', L::One, S::Partial, '_timelineAll timeline-postform (textarea + counter + photo + public flag)', 'the OpenPNE 3 inline form, revealed by classic-timeline-compose.js and submitting as a normal POST back to the page (OpenPNE 3 posts over the API in place); without the script the %Post_activity% link keeps the standalone /timeline/new path'),
                new ScreenElement('per-post reply form', L::Two, S::Deferred, 'timelineTemplate #timeline-post-comment-form', 'each row\'s コメントする link jumps to the thread page\'s reply form; the inline form itself is not rendered'),
                new ScreenElement('own-post delete', L::Two, S::Ported, 'timelineTemplate timeline-post-delete-confirm', 'delete link + confirm page on the viewer\'s own posts; OpenPNE 3 uses an inline JS confirm'),
            ],
            // memberSuccess.php → timelineProfile component → timeline/member.blade.php
            'member' => [
                new ScreenElement('author nickname + profile link', L::Two, S::Ported, 'timelineTemplate <a href="${member.profile_url}">${member.name}', 'Classic links the nickname server-side; OpenPNE 3 builds the post client-side from the API'),
                new ScreenElement('author avatar (48px, rounded)', L::Two, S::Ported, 'timelineTemplate ${member.profile_image} + timeline.css', 'the 48px thumbnail in timeline-post-member-image, no_image fallback'),
                new ScreenElement('opTimelinePlugin stylesheets', L::Two, S::Partial, '_timelineProfile use_stylesheet bootstrap.css + timeline.css (component-driven)', 'pushed by every timeline-rendering screen and gadget (layout pluginCss stack, once per page); lightbox.css and jquery.colorbox.css stay unloaded with their scripts'),
                new ScreenElement('screen-name handle', L::Three, S::Deferred, 'timelineTemplate ${member.screen_name}', 'OpenPNE 3 shows the @screen_name handle; Classic shows the nickname'),
                new ScreenElement('activity body', L::Two, S::Partial, 'timelineTemplate {{html body_html}}', 'plain text; display-time URL auto-link rendered, rich-text decoration not'),
                new ScreenElement('attached image', L::Three, S::Ported, 'activity_image (opTimeline image) + lightbox.js', 'ActivityImage thumbnail via the shared File; FilePolicy-gated by the activity visibility'),
                new ScreenElement('visibility label', L::Three, S::Ported, 'timelineTemplate public_status friend/private', 'the 公開範囲 span for friend/private, as OpenPNE 3 draws it, plus Open — an OpenPNE 4-native audience worth naming; the members-wide default renders no label'),
                new ScreenElement('permalink + datetime', L::Three, S::Ported, 'timelineTemplate timeline/show/id/${id} + jquery.timeago', 'absolute localized datetime linking to timeline.show; OpenPNE 3 renders a relative timeago'),
                new ScreenElement('pagination', L::Two, S::Partial, '_timelineProfile #timeline-loadmore もっと読む', 'a server-side pager (Classic pager parts); OpenPNE 3 loads more in place over the API — a future Classic JS-lane candidate'),
                new ScreenElement('compose box', L::One, S::Ported, '_timelineProfile (no form: only a hidden, disabled submit-button div)', 'OpenPNE 3 renders no compose form on the member timeline; OpenPNE 4 keeps its own %Post_activity% link to the standalone compose page'),
                new ScreenElement('per-post reply form', L::Two, S::Deferred, 'timelineTemplate #timeline-post-comment-form', 'each row\'s コメントする link jumps to the thread page\'s reply form; the inline form itself is not rendered'),
                new ScreenElement('own-post delete', L::Two, S::Ported, 'timelineTemplate timeline-post-delete-confirm', 'delete link + confirm page; OpenPNE 3 uses an inline JS confirm'),
            ],
            // showSuccess.php → timeline/show.blade.php
            'show' => [
                new ScreenElement('single activity (author, body, datetime)', L::One, S::Ported, 'showSuccess $activity->getMember()->getName() + timelineTemplate'),
                new ScreenElement('author avatar (48px, rounded)', L::Two, S::Ported, 'timelineTemplate ${member.profile_image} + timeline.css', 'the 48px thumbnail in timeline-post-member-image, no_image fallback'),
                new ScreenElement('opTimelinePlugin stylesheets', L::Two, S::Partial, 'timeline actions addStyleSheet + use_stylesheet (component-driven)', 'pushed by every timeline-rendering screen and gadget (layout pluginCss stack, once per page); lightbox.css and jquery.colorbox.css stay unloaded with their scripts'),
                new ScreenElement('screen-name handle', L::Three, S::Deferred, 'timelineTemplate ${member.screen_name}', 'OpenPNE 3 shows the @screen_name handle; Classic shows the nickname'),
                new ScreenElement('activity body', L::Two, S::Partial, 'timelineTemplate {{html body_html}}', 'plain text; display-time URL auto-link rendered, rich-text decoration not'),
                new ScreenElement('attached image', L::Three, S::Ported, 'activity_image (opTimeline image) + lightbox.js', 'ActivityImage thumbnail via the shared File; FilePolicy-gated by the activity visibility'),
                new ScreenElement('visibility label', L::Three, S::Ported, 'timelineTemplate public_status friend/private', 'the 公開範囲 span for friend/private, as OpenPNE 3 draws it, plus Open — an OpenPNE 4-native audience worth naming; the members-wide default renders no label'),
                new ScreenElement('reply thread', L::One, S::Ported, 'showSuccess gorgon timeline-list (commentSearch API)', 'replies rendered server-side oldest-first; OpenPNE 3 streams them from the API. A reply permalink re-centers to the thread root'),
                new ScreenElement('reply form', L::Two, S::Ported, 'timelineTemplate #timeline-post-comment-form', 'reply posts to the thread root, inheriting its audience'),
                new ScreenElement('reply count + "show all"', L::Three, S::Partial, 'opTimeline COMMENT_DISPLAY_MAX + showAllComment', 'all replies are shown; OpenPNE 3 caps at 10 with a show-all control'),
                new ScreenElement('own-post delete', L::Two, S::Ported, 'timelineTemplate timeline-post-delete-confirm', 'delete link + confirm page on the viewer\'s own post and replies'),
            ],
        ];
    }
}
