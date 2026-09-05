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
            // OpenPNE 3's PC sns_timeline forwarded to the error page (the feed lived on mobile and in
            // the homeAllTimeline gadget); OpenPNE 4 answers the URL with the /timeline page.
            new RouteMap('sns_timeline', '/sns/timeline', 'timeline.index', 'GET', op3Action: 'sns'),
            // The community timeline was replaced by group talk, so its OpenPNE 3 URL lands on the
            // conversation that took its place.
            new RouteMap('community_timeline', '/community/:id/timeline', 'group.talk.show', 'GET', op3Action: 'community'),
            // OpenPNE 3 reached the single-activity page through the global /:module/:action fallback
            // (/timeline/show/id/:id), so there is no named route — a fallback-only map that still
            // derives the page_timeline_show body id.
            new RouteMap(null, null, 'timeline.show', 'GET', op3Action: 'show'),
        ];
    }

    public function gaps(): array
    {
        return [];
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
            // The group timeline's OpenPNE 3 URL, and the global-fallback spelling of it that
            // OpenPNE 3 also answered → the group talk that replaced it.
            '/community/:id/timeline' => 'group.talk.show',
            '/timeline/community/id/:id' => 'group.talk.show',
        ];
    }

    public function screens(): array
    {
        return [
            // _timelineAll.php (the homeAllTimeline home gadget) + _timelineTemplate.php → timeline/index.blade.php
            'sns' => [
                new ScreenElement('author nickname + profile link', L::Two, S::Ported, 'timelineTemplate <a href="${member.profile_url}">${member.name}', 'cross-member feed; Classic links the nickname server-side, OpenPNE 3 builds each post client-side from the API'),
                new ScreenElement('author avatar (48px, rounded)', L::Two, S::Ported, 'timelineTemplate ${member.profile_image} + timeline.css', 'the 48px thumbnail in timeline-post-member-image, no_image fallback'),
                new ScreenElement('opTimelinePlugin stylesheets', L::Two, S::Partial, '_timelineAll use_stylesheet bootstrap.css + timeline.css + counter.css (component-driven)', 'bootstrap and timeline.css come from every timeline-rendering screen and gadget, counter.css only with the compose box (layout pluginCss stack, once per page); lightbox.css and jquery.colorbox.css stay unloaded with their scripts; classic-timeline.css follows with the first-party rules for the dialogs and the load-more box'),
                new ScreenElement('screen-name handle', L::Three, S::Deferred, 'timelineTemplate ${member.screen_name}', 'OpenPNE 3 shows the @screen_name handle; Classic shows the nickname'),
                new ScreenElement('activity body', L::Two, S::Partial, 'timelineTemplate {{html body_html}}', 'OpenPNE 3 PC ran htmlspecialchars → nl2br → convCmd (op_decoration is mobile-only): the auto-link is rendered, the convCmd inline embeds (image URLs, YouTube, niconico, maps) are not — the players fall to the link card in another shape, an image URL to nothing yet'),
                new ScreenElement('attached image', L::Three, S::Ported, 'activity_image (opTimeline image) + lightbox.js', 'ActivityImage thumbnail via the shared File, FilePolicy-gated by the activity visibility, inside OpenPNE 3\'s rel=lightbox link to the full-size file; classic-timeline-dialogs.js opens it in a first-party <dialog> — Lightbox 2\'s #lightbox DOM is not reproduced'),
                new ScreenElement('visibility label', L::Three, S::Ported, 'timelineTemplate public_status friend/private', 'the 公開範囲 span for friend/private, as OpenPNE 3 draws it, plus Open — an OpenPNE 4-native audience worth naming; the members-wide default renders no label'),
                new ScreenElement('permalink + datetime', L::Three, S::Ported, 'timelineTemplate timeline/show/id/${id} + jquery.timeago', 'OpenPNE 3\'s jquery.timeago ladder and words, counted by classic-timeago.js from data-datetime (ISO 8601) and again each minute; without the script the absolute datetime stands, which is also the hover title — OpenPNE 3 kept an RFC 2822 date in the title for timeago to read'),
                new ScreenElement('pagination', L::Two, S::Ported, '_timelineAll #timeline-loadmore もっと読む', 'the OpenPNE 3 button, appending the next page from timeline.index.rows (rows fragment, next page in a Link header); without the script the Classic pager parts stand in, and they return when a fetch fails. Offset paging where OpenPNE 3 keyed on max_id: a post made meanwhile shifts the next page by one'),
                new ScreenElement('compose box', L::One, S::Partial, '_timelineAll timeline-postform (textarea + counter + photo + public flag)', 'the OpenPNE 3 inline form, revealed by classic-timeline-compose.js and submitting as a normal POST back to the page (OpenPNE 3 posts over the API in place); without the script the %Post_activity% link keeps the standalone /timeline/new path'),
                new ScreenElement('site-wide posting switch', L::Two, S::Ported, 'sns_config is_allow_post_activity: the form is withheld and updateActivity forward404s', 'SnsSettingKey::TimelinePostingEnabled through EnsureTimelinePostingEnabled (404 on the compose page, post and reply routes) and TimelinePosting::enabled() on every compose and reply affordance, replies included as OpenPNE 3\'s API refused them under the same switch'),
                new ScreenElement('per-post reply form', L::Two, S::Ported, 'timelineTemplate #timeline-post-comment-form', 'the OpenPNE 3 inline form under each row, with the last ten replies above it and 以前のコメントを見る past them; a plain body input (the @mention picker is on the thread page, as OpenPNE 3 had it), and without the script the コメントする link keeps its jump to that page'),
                new ScreenElement('own-post delete', L::Two, S::Ported, 'timelineTemplate timeline-post-delete-confirm', 'the delete link opens timelineTemplate\'s confirm block in a <dialog> (OpenPNE 3: colorbox) and the row leaves the page on the JSON answer; without the script the link is the confirm page'),
            ],
            // memberSuccess.php → timelineProfile component → timeline/member.blade.php
            'member' => [
                new ScreenElement('author nickname + profile link', L::Two, S::Ported, 'timelineTemplate <a href="${member.profile_url}">${member.name}', 'Classic links the nickname server-side; OpenPNE 3 builds the post client-side from the API'),
                new ScreenElement('author avatar (48px, rounded)', L::Two, S::Ported, 'timelineTemplate ${member.profile_image} + timeline.css', 'the 48px thumbnail in timeline-post-member-image, no_image fallback'),
                new ScreenElement('opTimelinePlugin stylesheets', L::Two, S::Partial, '_timelineProfile use_stylesheet bootstrap.css + timeline.css (component-driven)', 'pushed by every timeline-rendering screen and gadget (layout pluginCss stack, once per page); lightbox.css and jquery.colorbox.css stay unloaded with their scripts; classic-timeline.css follows with the first-party rules for the dialogs and the load-more box'),
                new ScreenElement('screen-name handle', L::Three, S::Deferred, 'timelineTemplate ${member.screen_name}', 'OpenPNE 3 shows the @screen_name handle; Classic shows the nickname'),
                new ScreenElement('activity body', L::Two, S::Partial, 'timelineTemplate {{html body_html}}', 'OpenPNE 3 PC ran htmlspecialchars → nl2br → convCmd (op_decoration is mobile-only): the auto-link is rendered, the convCmd inline embeds (image URLs, YouTube, niconico, maps) are not — the players fall to the link card in another shape, an image URL to nothing yet'),
                new ScreenElement('attached image', L::Three, S::Ported, 'activity_image (opTimeline image) + lightbox.js', 'ActivityImage thumbnail via the shared File, FilePolicy-gated by the activity visibility, inside OpenPNE 3\'s rel=lightbox link to the full-size file; classic-timeline-dialogs.js opens it in a first-party <dialog> — Lightbox 2\'s #lightbox DOM is not reproduced'),
                new ScreenElement('visibility label', L::Three, S::Ported, 'timelineTemplate public_status friend/private', 'the 公開範囲 span for friend/private, as OpenPNE 3 draws it, plus Open — an OpenPNE 4-native audience worth naming; the members-wide default renders no label'),
                new ScreenElement('permalink + datetime', L::Three, S::Ported, 'timelineTemplate timeline/show/id/${id} + jquery.timeago', 'OpenPNE 3\'s jquery.timeago ladder and words, counted by classic-timeago.js from data-datetime (ISO 8601) and again each minute; without the script the absolute datetime stands, which is also the hover title — OpenPNE 3 kept an RFC 2822 date in the title for timeago to read'),
                new ScreenElement('pagination', L::Two, S::Ported, '_timelineProfile #timeline-loadmore もっと読む', 'the OpenPNE 3 button, appending the next page from timeline.member.rows (rows fragment, next page in a Link header); without the script the Classic pager parts stand in, and they return when a fetch fails. Offset paging where OpenPNE 3 keyed on max_id: a post made meanwhile shifts the next page by one'),
                new ScreenElement('compose box', L::One, S::Ported, '_timelineProfile (no form: only a hidden, disabled submit-button div)', 'OpenPNE 3 renders no compose form on the member timeline, and neither does OpenPNE 4: posting is the home gadget\'s box, or its standalone page without the script'),
                new ScreenElement('per-post reply form', L::Two, S::Ported, 'timelineTemplate #timeline-post-comment-form', 'the OpenPNE 3 inline form under each row, with the last ten replies above it and 以前のコメントを見る past them; a plain body input (the @mention picker is on the thread page, as OpenPNE 3 had it), and without the script the コメントする link keeps its jump to that page'),
                new ScreenElement('own-post delete', L::Two, S::Ported, 'timelineTemplate timeline-post-delete-confirm', 'the delete link opens timelineTemplate\'s confirm block in a <dialog> (OpenPNE 3: colorbox) and the row leaves the page on the JSON answer; without the script the link is the confirm page'),
            ],
            // communitySuccess.php + _timelineCommunity.php (also injected into the community home) → no
            // Classic screen: the URL redirects to Modern-only group talk
            'community' => [
                new ScreenElement('%community% timeline box', L::One, S::Missing, 'opTimelinePlugin community/config/view.yml homeSuccess customize timelineCommunity (parts communityHome, target before) + _timelineCommunity.php div#communityTimeline.dparts.communityTimeline', 'no Classic screen: the URL redirects to the Modern talk surface (GroupTalkController::show), so nothing under this body id renders in Classic; the entrance link box on the community home is inventoried there'),
                new ScreenElement('member-only rendering', L::One, S::Missing, '_timelineCommunity.php if ($community->isPrivilegeBelong($memberId)) + api/activity search forward400Unless CommunityMember::isMember', 'no Classic screen; the Modern talk surface reads by GroupTalkAccess::canView (the topic_read_access column), so an Everyone group opens talk to a non-member where OpenPNE 3 drew nothing'),
                new ScreenElement('box heading (%community% name + activity term)', L::Three, S::Deferred, "_timelineCommunity.php div.partsHeading h3 \$community->getName() . \$op_term['activity']", 'served by the Modern talk surface (Classic renders a link box), which heads the conversation with the group name'),
                new ScreenElement('compose box (textarea + post button)', L::One, S::Deferred, '_timelineCommunity.php div.timeline-postform > #timeline-textarea + #timeline-submit-button', 'served by the Modern talk surface (Classic renders a link box); the community box carried no public-flag select (unlike _timelineAll.php) because the %community% was the audience'),
                new ScreenElement('photo attachment', L::Two, S::Deferred, '_timelineCommunity.php #timeline-upload-photo-button + #timeline-submit-upload (jquery.upload-1.0.2.js)', 'served by the Modern talk surface (Classic renders a link box), which takes up to PostImages::MAX_IMAGES per message where OpenPNE 3 took one'),
                new ScreenElement('140-character counter', L::Three, S::Missing, '_timelineCommunity.php var MAXLENGTH = 140 + counter.js #counter', 'neither surface counts: talk caps the body at 5,000 code points (TalkBody) and shows no counter'),
                new ScreenElement('post rows (avatar + author link)', L::Two, S::Deferred, 'timelineTemplate .timeline-post-member-image + a.screen-name ${member.screen_name}', 'served by the Modern talk surface (Classic renders a link box)'),
                new ScreenElement('post body', L::Two, S::Deferred, 'timelineTemplate {{html body_html}}', 'served by the Modern talk surface (Classic renders a link box)'),
                new ScreenElement('attached image', L::Three, S::Deferred, 'timelineTemplate + lightbox.js (viewPhoto), opTimeline::embedImageUrlToContentForSearchAPI', 'served by the Modern talk surface (Classic renders a link box)'),
                new ScreenElement('permalink + relative timestamp', L::Three, S::Deferred, 'timelineTemplate timeline/show/id/${id} + span.timestamp.timeago', 'served by the Modern talk surface (Classic renders a link box), where a message is addressed by the conversation URL plus ?m= rather than a page of its own'),
                new ScreenElement('per-row visibility label', L::Three, S::Missing, "timelineTemplate {{if public_status == 'friend'}} span.public-flag", 'a talk message carries no audience of its own (docs/internals/group-talk.md), so there is no per-row label to draw'),
                new ScreenElement('own-post delete', L::Two, S::Deferred, 'timelineTemplate .timeline-post-delete-confirm (削除する link + .timeline-post-delete-button)', 'served by the Modern talk surface (Classic renders a link box)'),
                new ScreenElement('per-post comment thread + inline comment form', L::Two, S::Deferred, 'timelineTemplate #timeline-post-comment-form-${id} + .timeline-comment-loadmore + timelineCommentTemplate', 'served by the Modern talk surface (Classic renders a link box), whose conversation is linear: a message answers another by reference instead of opening a thread under it'),
                new ScreenElement('@name reply insertion', L::Three, S::Deferred, 'timelineTemplate addreply() + a.reply (SnsConfig op_timeline_plugin_timeline_comment_reply)', 'served by the Modern talk surface (Classic renders a link box), whose composer resolves an @mention instead of prefixing the name as text'),
                new ScreenElement('load-more posts', L::Two, S::Deferred, '_timelineCommunity.php button#timeline-loadmore もっと読む', 'served by the Modern talk surface (Classic renders a link box), which walks back by keyset'),
                new ScreenElement('60-second poll for new posts', L::Three, S::Deferred, '_timelineCommunity.php gorgon.timerCount 60000 + timeline-loader.api.js', 'served by the Modern talk surface (Classic renders a link box), which polls on its own interval while the tab is visible'),
                new ScreenElement('desktop notification of new posts', L::Three, S::Deferred, '_timelineCommunity.php gorgon.notify + jquery.desktopify.js (title: %community% name + の最新投稿, 48x48 icon)', 'served by the Modern talk surface (Classic renders a link box) through the notification catalog and web push, on the site talk notify mode rather than a popup for every post an open page sees'),
                new ScreenElement('left-member row filter', L::Two, S::Missing, 'opActivityQueryBuilder::buildQuery EXISTS (FROM CommunityMember cm WHERE cm.member_id = a.member_id AND cm.community_id = ?)', 'talk applies no per-row filter by contract (GroupTalkAccess), so a message from someone who has left the group stays in the conversation'),
                new ScreenElement('opTimelinePlugin stylesheets', L::Two, S::Missing, '_timelineCommunity.php use_stylesheet bootstrap.css + timeline.css + counter.css + lightbox.css', 'no Classic screen renders the timeline box, so the group home pushes none of them'),
            ],
            // showSuccess.php → timeline/show.blade.php
            'show' => [
                new ScreenElement('single activity (author, body, datetime)', L::One, S::Ported, 'showSuccess $activity->getMember()->getName() + timelineTemplate'),
                new ScreenElement('author avatar (48px, rounded)', L::Two, S::Ported, 'timelineTemplate ${member.profile_image} + timeline.css', 'the 48px thumbnail in timeline-post-member-image, no_image fallback'),
                new ScreenElement('opTimelinePlugin stylesheets', L::Two, S::Partial, 'timeline actions addStyleSheet + use_stylesheet (component-driven)', 'pushed by every timeline-rendering screen and gadget (layout pluginCss stack, once per page); lightbox.css and jquery.colorbox.css stay unloaded with their scripts; classic-timeline.css follows with the first-party rules for the dialogs and the load-more box'),
                new ScreenElement('screen-name handle', L::Three, S::Deferred, 'timelineTemplate ${member.screen_name}', 'OpenPNE 3 shows the @screen_name handle; Classic shows the nickname'),
                new ScreenElement('activity body', L::Two, S::Partial, 'timelineTemplate {{html body_html}}', 'OpenPNE 3 PC ran htmlspecialchars → nl2br → convCmd (op_decoration is mobile-only): the auto-link is rendered, the convCmd inline embeds (image URLs, YouTube, niconico, maps) are not — the players fall to the link card in another shape, an image URL to nothing yet'),
                new ScreenElement('attached image', L::Three, S::Ported, 'activity_image (opTimeline image) + lightbox.js', 'ActivityImage thumbnail via the shared File, FilePolicy-gated by the activity visibility, inside OpenPNE 3\'s rel=lightbox link to the full-size file; classic-timeline-dialogs.js opens it in a first-party <dialog> — Lightbox 2\'s #lightbox DOM is not reproduced'),
                new ScreenElement('visibility label', L::Three, S::Ported, 'timelineTemplate public_status friend/private', 'the 公開範囲 span for friend/private, as OpenPNE 3 draws it, plus Open — an OpenPNE 4-native audience worth naming; the members-wide default renders no label'),
                new ScreenElement('reply thread', L::One, S::Ported, 'showSuccess gorgon timeline-list (commentSearch API)', 'replies rendered server-side oldest-first; OpenPNE 3 streams them from the API. A reply permalink re-centers to the thread root'),
                new ScreenElement('reply form', L::Two, S::Ported, 'timelineTemplate #timeline-post-comment-form', 'reply posts to the thread root, inheriting its audience'),
                new ScreenElement('reply count + "show all"', L::Three, S::Partial, 'opTimeline COMMENT_DISPLAY_MAX + showAllComment', 'all replies are shown; OpenPNE 3 caps at 10 with a show-all control'),
                new ScreenElement('own-post delete', L::Two, S::Ported, 'timelineTemplate timeline-post-delete-confirm', 'the delete link opens timelineTemplate\'s confirm block in a <dialog> (OpenPNE 3: colorbox); a reply leaves the page on the JSON answer, the thread root posts as the page it is on; without the script the link is the confirm page'),
            ],
        ];
    }
}
