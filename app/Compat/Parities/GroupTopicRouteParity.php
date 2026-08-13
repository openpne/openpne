<?php

namespace App\Compat\Parities;

use App\Compat\CompatLevel as L;
use App\Compat\RouteMap;
use App\Compat\RouteParity;
use App\Compat\ScreenElement;
use App\Compat\ScreenStatus as S;

class GroupTopicRouteParity extends RouteParity
{
    protected string $module = 'communityTopic';

    public function maps(): array
    {
        return [
            new RouteMap('communityTopic_list_community', '/communityTopic/listCommunity/:id', 'group.topics.index', 'GET', op3Action: 'listCommunity'),
            new RouteMap('communityTopic_new', '/communityTopic/new/:id', 'group.topics.new', 'GET', op3Action: 'new'),
            new RouteMap('communityTopic_create', '/communityTopic/create/:id', 'group.topics.store', 'POST'),
            new RouteMap('communityTopic_show', '/communityTopic/:id', 'group.topics.show', 'GET', op3Action: 'show'),
            new RouteMap('communityTopic_edit', '/communityTopic/edit/:id', 'group.topics.edit', 'GET', op3Action: 'edit'),
            new RouteMap('communityTopic_update', '/communityTopic/update/:id', 'group.topics.update', 'POST'),
            new RouteMap('communityTopic_delete_confirm', '/communityTopic/deleteConfirm/:id', 'group.topics.delete.show', 'GET', op3Action: 'deleteConfirm'),
            new RouteMap('communityTopic_delete', '/communityTopic/delete/:id', 'group.topics.delete', 'POST'),

            // communityTopicComment module: rendered under page_communityTopicComment_* (op3Module
            // override). create keys off the topic id; deleteConfirm/delete key off the comment id.
            new RouteMap('communityTopic_comment_create', '/communityTopic/:id/comment/create', 'group.topics.comment.store', 'POST'),
            new RouteMap('communityTopic_comment_delete_confirm', '/communityTopic/comment/deleteConfirm/:id', 'group.topics.comment.delete.show', 'GET',
                op3Action: 'deleteConfirm', op3Module: 'communityTopicComment'),
            new RouteMap('communityTopic_comment_delete', '/communityTopic/comment/delete/:id', 'group.topics.comment.delete', 'POST'),
        ];
    }

    public function gaps(): array
    {
        return [
            // Cross-community "recently updated topics" feed (ordered by updated_at, not the
            // topic_updated_at widget).
            'communityTopic_recently_topic_list' => 'Cross-community recently-updated topics feed; a sidebar feature, not ported.',
            // Topic keyword search. OpenPNE 3 routes it through one search surface shared with the
            // event plugin, a separate surface neither board adapter provides.
            'communityTopic_search' => 'Per-community topic search; routes to the shared topic/event search form, a separate surface the board adapter does not provide.',
            'communityTopic_search_all' => 'Global topic search; routes to the shared topic/event search form, a separate surface the board adapter does not provide.',
            'communityTopic_search_form' => 'The shared topic/event search form page; a separate search surface the board adapter does not provide.',
            'config_community_topic_notification_mail' => 'Per-community topic notification-mail opt-in; OpenPNE 4 notification opt-outs are member-level, with no per-community toggle.',
        ];
    }

    public function compatRedirects(): array
    {
        // Every OpenPNE 3 /communityTopic/* GET URL: the canonical board moved under its group
        // (/groups/:id/topics) and the thread to a flat permalink (/topics/:id), so each is served
        // by a redirect (routes/web.php).
        return [
            '/communityTopic/listCommunity/:id' => 'group.topics.index',
            '/communityTopic/new/:id' => 'group.topics.new',
            '/communityTopic/:id' => 'group.topics.show',
            '/communityTopic/edit/:id' => 'group.topics.edit',
            '/communityTopic/deleteConfirm/:id' => 'group.topics.delete.show',
            '/communityTopic/comment/deleteConfirm/:id' => 'group.topics.comment.delete.show',
        ];
    }

    public function acknowledgesGlobalFallback(): bool
    {
        // communityTopic_nodefaults (/communityTopic/* → default/error) disables the global
        // /:module/:action fallback, so the named routes are the complete reachable set.
        return false;
    }

    /**
     * Surface elements per opCommunityTopicPlugin communityTopic template, against
     * resources/views/group-topic/*.blade.php. Levels follow
     * docs/internals/classic-compatibility.md; an item short of a faithful port records why.
     */
    public function screens(): array
    {
        return [
            // listCommunitySuccess.php → group-topic/index.blade.php
            'listCommunity' => [
                new ScreenElement('topic list (dl: last-activity datetime / name(count) link)', L::Two, S::Ported, 'listCommunitySuccess.php recentList dl > dt + dd', 'OpenPNE 4 trails the link with the author name, prints an empty-state message where OpenPNE 3 dropped the whole box, and closes the page with a line box back to the community'),
                new ScreenElement('create-topic entry', L::Two, S::Partial, "op_include_parts('buttonBox', 'communityTopicList', button Create)", 'folded into the board box as a moreInfo link; OpenPNE 3 gave it its own box with a submit button'),
                new ScreenElement('pager navigation (above and below)', L::Two, S::Ported, "op_include_pager_navigation(\$pager, '@communityTopic_list_community')"),
                new ScreenElement('box heading', L::Three, S::Partial, 'listCommunitySuccess.php <h3>List of topics</h3>', 'headed with the community name instead'),
            ],
            // showSuccess.php + communityTopicComment/_list.php → group-topic/show.blade.php.
            // The comment delete confirm shares this action key (op3Module communityTopicComment),
            // so its elements live at the end of the comment group below.
            'show' => [
                new ScreenElement('topicDetailBox article box', L::Two, S::Ported, 'showSuccess.php <div class="dparts topicDetailBox">'),
                new ScreenElement('article dl / dt / dd structure', L::Two, S::Missing, 'showSuccess.php dl > dt(datetime) + dd > div.title / div.name / div.body', 'rendered as p.topicMeta + div.topicBody, so opCommunityTopicPlugin\'s `.topicDetailBox dl/dt/dd` rules — the article frame and its datetime column — match nothing'),
                new ScreenElement('box heading "[community] %topic%"', L::Three, S::Partial, "showSuccess.php <h3>'['.\$group->getName().'] '.\$topicLabel</h3>", 'headed with the topic name, so the owning community is no longer named there'),
                new ScreenElement('author link', L::Two, S::Ported, 'op_community_topic_link_to_member($communityTopic->getMember())'),
                new ScreenElement('created-at datetime', L::Three, S::Ported, "nl2br(op_format_date(created_at, 'XDateTimeJaBr'))", 'LocalizedDate; inline single-line'),
                new ScreenElement('article images (ul.photo, 120×120 linking to full size)', L::Two, S::Ported, '$communityTopic->getImages() ul.photo', 'the shared _images partial; each fetch is FilePolicy-gated by the board visibility'),
                new ScreenElement('article body line breaks + auto-link', L::Two, S::Ported, 'op_url_cmd(nl2br($communityTopic->getBody()))', 'x-user-text (BodyText)'),
                new ScreenElement('edit entry', L::Two, S::Partial, 'showSuccess.php div.operation > form > ul.button input Edit', 'a text link pair (Edit / Delete); OpenPNE 3 had a centered submit button and reached deletion from the edit screen only'),
                new ScreenElement('comment thread (number, author, delete)', L::One, S::Ported, "include_component('communityTopicComment', 'list')"),
                new ScreenElement('comment pagination + order toggle', L::Two, S::Ported, '_list.php op_include_pager_navigation (reversible)', 'GroupTopicCommentThread: fixed size 20, older/newer + latest/oldest toggle'),
                new ScreenElement('comment datetime', L::Three, S::Ported, "nl2br(op_format_date(created_at, 'XDateTimeJaBr'))", 'LocalizedDate; inline single-line'),
                new ScreenElement('comment images', L::Three, S::Ported, '_list.php $comment->getImages() ul.photo'),
                new ScreenElement('comment body line breaks + auto-link', L::Three, S::Ported, 'op_url_cmd(nl2br($comment->getBody()))', 'x-user-text (BodyText)'),
                new ScreenElement('reply (>>N) quote link', L::Three, S::Missing, '_list.php a.reply + SnsConfig op_community_topic_plugin_community_topic_comment_reply', 'the link that prepends ">>N name" into the comment textarea is not ported'),
                new ScreenElement('comment post form + image upload', L::One, S::Ported, "op_include_form('formCommunityTopicComment', isMultipart)", 'up to PostImages::MAX_IMAGES on one Images row; OpenPNE 3 gave each photo its own labelled row'),
                new ScreenElement('comment delete confirmation', L::Two, S::Ported, "communityTopicComment/deleteConfirmSuccess.php op_include_form('deleteConfirmForm')", 'a screen of its own, folded here because it shares the deleteConfirm action key. OpenPNE 4 adds the question line and a blockquote.commentPreview, and replaces the history.back() line with a Back link'),
                new ScreenElement('required-field markers', L::Three, S::Missing, "_partsForm.php mark_required_field + '%0% is required field.'", 'no per-label * marker and no notice line; the inputs carry the HTML required attribute instead'),
                new ScreenElement('community top-page line link', L::Two, S::Partial, "op_include_line('linkLine', link_to('community/home'))", 'links to the topic board, not the community home, and drops the "[name] %Community% Top Page" label'),
            ],
            // newSuccess.php (PluginCommunityTopicForm) → group-topic/new.blade.php
            'new' => [
                new ScreenElement('title input', L::Two, S::Ported, 'PluginCommunityTopicForm name sfWidgetFormInput'),
                new ScreenElement('body textarea', L::Two, S::Ported, 'BaseCommunityTopicForm body', 'OpenPNE 4 adds the shared body-format toggle'),
                new ScreenElement('image upload (×3)', L::Two, S::Partial, 'photo_1..3 embedded opCommunityTopicPluginImageForm, ul#community_topic_photo_N', 'one Images row with MAX_IMAGES file inputs; OpenPNE 3 gave each photo its own labelled row inside a ul#community_topic_photo_N'),
                new ScreenElement('post button', L::Two, S::Ported, "op_include_form('formCommunityTopic') button", 'relabelled from Send, with a Cancel link beside it'),
                new ScreenElement('required-field markers', L::Three, S::Missing, "_partsForm.php mark_required_field + '%0% is required field.'", 'no per-label * marker and no notice line; the inputs carry the HTML required attribute instead'),
            ],
            // editSuccess.php (same form as new) → group-topic/edit.blade.php
            'edit' => [
                new ScreenElement('title input', L::Two, S::Ported, 'PluginCommunityTopicForm name sfWidgetFormInput'),
                new ScreenElement('body textarea', L::Two, S::Ported, 'BaseCommunityTopicForm body', 'OpenPNE 4 adds the shared body-format toggle'),
                new ScreenElement('existing image edit / delete', L::Two, S::Partial, 'communityTopic/_formEditImage.php (thumbnail + %input% + %delete%)', 'a current-images list with remove_images[] checkboxes; OpenPNE 3 let each slot be replaced in place'),
                new ScreenElement('new image upload (×3)', L::Two, S::Partial, 'photo_1..3 embedded opCommunityTopicPluginImageForm', 'one Images row with MAX_IMAGES file inputs; OpenPNE 3 gave each photo its own labelled row'),
                new ScreenElement('save button', L::Two, S::Ported, "op_include_form('formCommunityTopic') button", 'relabelled from Send, with a Cancel link beside it'),
                new ScreenElement('delete-topic box', L::Two, S::Ported, "op_include_parts('buttonBox', 'toDelete')", 'GET form to the delete confirm page'),
                new ScreenElement('required-field markers', L::Three, S::Missing, "_partsForm.php mark_required_field + '%0% is required field.'", 'no per-label * marker and no notice line; the inputs carry the HTML required attribute instead'),
            ],
            // deleteConfirmSuccess.php → group-topic/delete.blade.php
            'deleteConfirm' => [
                new ScreenElement('delete confirmation form', L::One, S::Ported, "op_include_form('deleteConfirmForm', \$form, title)", 'OpenPNE 4 adds the question paragraph OpenPNE 3 left to the box title'),
                new ScreenElement('back-to-previous line', L::Three, S::Partial, "op_include_line('backLink', link_to_function(history.back()))", 'a Cancel link to the topic instead of the JavaScript line box'),
            ],
        ];
    }
}
