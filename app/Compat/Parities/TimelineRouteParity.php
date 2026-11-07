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
                new ScreenElement('pagination', L::Two, S::Ported, '_timelineAll #timeline-loadmore もっと読む', 'the OpenPNE 3 button, appending the next page from timeline.index.rows (rows fragment, next page in a Link header); without the script the Classic pager parts stand in, and they return when a fetch fails. Keyset on (created_at, id) as OpenPNE 3 keyed on max_id, so a post made meanwhile shifts nothing; the pager reads out no count'),
                new ScreenElement('compose box', L::One, S::Partial, '_timelineAll timeline-postform (textarea + counter + photo + public flag)', 'the OpenPNE 3 inline form, revealed by classic-timeline-compose.js and submitting as a normal POST back to the page (OpenPNE 3 posts over the API in place); without the script the %Post_activity% link keeps the standalone /timeline/new path'),
                new ScreenElement('template activity for a new diary / topic / event', L::Two, S::Ported, 'opDiaryPlugin / opCommunityTopicPlugin postInsert → ActivityDataTable::updateActivity (sns_config op_diary_plugin_update_activity / op_community_topic_plugin_update_activity)', 'AnnounceOnTimeline under DiaryAutoTimelinePost / GroupAutoTimelinePost: the line plus the record URL as its link card; a members-only group gets no line where OpenPNE 3 wrote an author-only one'),
                new ScreenElement('site-wide posting switch', L::Two, S::Ported, 'sns_config is_allow_post_activity: _timelineAll still drew the form, and activity/post.json answered "you are not allowed this action"', 'SnsSettingKey::TimelinePostingEnabled through EnsureTimelinePostingEnabled (404 on the compose page, the post, reply and mention-picker routes) and TimelinePosting::enabled() withholding every compose and reply affordance — tighter than OpenPNE 3, whose PC timeline offered the form and refused the POST; replies included, as that API refused them under the same switch'),
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
                new ScreenElement('pagination', L::Two, S::Ported, '_timelineProfile #timeline-loadmore もっと読む', 'the OpenPNE 3 button, appending the next page from timeline.member.rows (rows fragment, next page in a Link header); without the script the Classic pager parts stand in, and they return when a fetch fails. Keyset on (created_at, id) as OpenPNE 3 keyed on max_id, so a post made meanwhile shifts nothing; the pager reads out no count'),
                new ScreenElement('compose box', L::One, S::Ported, '_timelineProfile (no form: only a hidden, disabled submit-button div)', 'OpenPNE 3 renders no compose form on the member timeline, and neither does OpenPNE 4: posting is the home gadget\'s box, or its standalone page without the script'),
                new ScreenElement('per-post reply form', L::Two, S::Ported, 'timelineTemplate #timeline-post-comment-form', 'the OpenPNE 3 inline form under each row, with the last ten replies above it and 以前のコメントを見る past them; a plain body input (the @mention picker is on the thread page, as OpenPNE 3 had it), and without the script the コメントする link keeps its jump to that page'),
                new ScreenElement('own-post delete', L::Two, S::Ported, 'timelineTemplate timeline-post-delete-confirm', 'the delete link opens timelineTemplate\'s confirm block in a <dialog> (OpenPNE 3: colorbox) and the row leaves the page on the JSON answer; without the script the link is the confirm page'),
            ],
            // communitySuccess.php + _timelineCommunity.php (also injected into the community home) →
            // group-talk/index.blade.php: the group talk that took in the community timeline
            'community' => [
                new ScreenElement('%community% timeline box', L::One, S::Ported, 'opTimelinePlugin community/config/view.yml homeSuccess customize timelineCommunity (parts communityHome, target before) + _timelineCommunity.php div#communityTimeline.dparts.communityTimeline', 'the talk screen draws the box at the OpenPNE 3 URL, newest first; the community home keeps a link box in the slot the customize injected it into'),
                new ScreenElement('member-only rendering', L::One, S::Missing, '_timelineCommunity.php if ($community->isPrivilegeBelong($memberId)) + api/activity search forward400Unless CommunityMember::isMember', 'talk reads by GroupTalkAccess::canView (the topic_read_access column), so an Everyone group opens the conversation to a non-member where OpenPNE 3 drew nothing; posting stays members-only'),
                new ScreenElement('box heading (%community% name + activity term)', L::Three, S::Ported, "_timelineCommunity.php div.partsHeading h3 \$community->getName() . \$op_term['activity']", 'the group name followed by the word talk, which is what the conversation is called in OpenPNE 4'),
                new ScreenElement('compose box (textarea + post button)', L::One, S::Ported, '_timelineCommunity.php div.timeline-postform > #timeline-textarea + #timeline-submit-button', 'the same form, submitting as a normal POST back to the page (OpenPNE 3 posted over the API in place); never hidden, since talk has no standalone compose page, so the controls show without the script'),
                new ScreenElement('photo attachment', L::Two, S::Ported, '_timelineCommunity.php #timeline-upload-photo-button + #timeline-submit-upload (jquery.upload-1.0.2.js)', 'one picture through the camera button, as OpenPNE 3 took; a picture may go alone, and without the script the file input itself stands in for the button'),
                new ScreenElement('140-character counter', L::Three, S::Partial, '_timelineCommunity.php var MAXLENGTH = 140 + counter.js #counter', 'the counter counts down from 5,000 (TalkBody), the talk cap, rather than 140'),
                new ScreenElement('post rows (avatar + author link)', L::Two, S::Ported, 'timelineTemplate .timeline-post-member-image + a.screen-name ${member.screen_name}', 'Classic links the nickname server-side, as the timeline rows do; a withdrawn author keeps the row under %Withdrawn_member%'),
                new ScreenElement('post body', L::Two, S::Ported, 'timelineTemplate {{html body_html}}', 'EntityText with the message\'s mention entities: auto-link and line breaks, no hashtags (talk parses none)'),
                new ScreenElement('attached image', L::Three, S::Ported, 'timelineTemplate + lightbox.js (viewPhoto), opTimeline::embedImageUrlToContentForSearchAPI', 'the 120px thumbnail inside OpenPNE 3\'s rel=lightbox link to the full-size file; classic-timeline-dialogs.js opens it in a first-party <dialog>'),
                new ScreenElement('permalink + relative timestamp', L::Three, S::Ported, 'timelineTemplate timeline/show/id/${id} + span.timestamp.timeago', 'the timeago stamp links to the conversation at ?m=, which opens with that message at the top of its page rather than on a page of its own'),
                new ScreenElement('per-row visibility label', L::Three, S::Missing, "timelineTemplate {{if public_status == 'friend'}} span.public-flag", 'a talk message carries no audience of its own (docs/internals/group-talk.md), so there is no per-row label to draw'),
                new ScreenElement('own-post delete', L::Two, S::Ported, 'timelineTemplate .timeline-post-delete-confirm (削除する link + .timeline-post-delete-button)', 'the delete link opens the confirm block in a <dialog> and the row leaves the page on the JSON answer; without the script the link is the confirm page. A group administrator may delete any message, which OpenPNE 3 did not offer'),
                new ScreenElement('per-post comment thread + inline comment form', L::Two, S::Partial, 'timelineTemplate #timeline-post-comment-form-${id} + .timeline-comment-loadmore + timelineCommentTemplate', 'the conversation is linear: コメントする opens the compose box in reply to the row, the answer is a message of its own, and a message answering another carries a one-line reference to it rather than a thread beneath it'),
                new ScreenElement('@name reply insertion', L::Three, S::Partial, 'timelineTemplate addreply() + a.reply (SnsConfig op_timeline_plugin_timeline_comment_reply)', 'the compose box resolves an @mention through the picker instead of prefixing the name as text'),
                new ScreenElement('load-more posts', L::Two, S::Ported, '_timelineCommunity.php button#timeline-loadmore もっと読む', 'the OpenPNE 3 button, appending the next page from group.talk.rows (rows fragment, next page in a Link header); without the script the Classic pager parts stand in. Keyset on (created_at, id), 20 a page as OpenPNE 3 counted'),
                new ScreenElement('60-second poll for new posts', L::Three, S::Deferred, '_timelineCommunity.php gorgon.timerCount 60000 + timeline-loader.api.js', 'the Classic screen does not poll; a reload is the way to newer messages, as on the Classic timeline'),
                new ScreenElement('desktop notification of new posts', L::Three, S::Deferred, '_timelineCommunity.php gorgon.notify + jquery.desktopify.js (title: %community% name + の最新投稿, 48x48 icon)', 'the Classic screen raises no popup; the notification catalog and web push carry new-message notice on the site talk notify mode'),
                new ScreenElement('left-member row filter', L::Two, S::Missing, 'opActivityQueryBuilder::buildQuery EXISTS (FROM CommunityMember cm WHERE cm.member_id = a.member_id AND cm.community_id = ?)', 'talk applies no per-row filter by contract (GroupTalkAccess), so a message from someone who has left the group stays in the conversation'),
                new ScreenElement('opTimelinePlugin stylesheets', L::Two, S::Partial, '_timelineCommunity.php use_stylesheet bootstrap.css + timeline.css + counter.css + lightbox.css', 'bootstrap, timeline.css and counter.css as the timeline screens push them (layout pluginCss stack, once per page); lightbox.css stays unloaded with its script, classic-timeline.css follows with the first-party rules'),
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
