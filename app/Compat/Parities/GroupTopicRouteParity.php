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
                new ScreenElement('topic list (dl: last-activity datetime / name(count) link)', L::Two, S::Ported, 'listCommunitySuccess.php recentList dl > dt + dd', 'one dl per row: the last-activity datetime in the dt, the name(count) link alone in the dd'),
                new ScreenElement('create-topic entry', L::Two, S::Ported, "op_include_parts('buttonBox', 'communityTopicList', button Create)", 'a buttonBox of its own with the Create submit'),
                new ScreenElement('pager navigation (above and below)', L::Two, S::Ported, "op_include_pager_navigation(\$pager, '@communityTopic_list_community')"),
                new ScreenElement('box heading', L::Three, S::Ported, 'listCommunitySuccess.php <h3>List of topics</h3>', 'List of %topics%'),
            ],
            // showSuccess.php + communityTopicComment/_list.php → group-topic/show.blade.php.
            'show' => [
                new ScreenElement('topicDetailBox article box', L::Two, S::Ported, 'showSuccess.php <div class="dparts topicDetailBox">'),
                new ScreenElement('article dl / dt / dd structure', L::Two, S::Ported, 'showSuccess.php dl > dt(datetime) + dd > div.title / div.name / div.body', 'dl > dt (stacked datetime) + dd > div.title / div.name / div.body > ul.photo + p.text (a Markdown body renders its own block instead of p.text)'),
                new ScreenElement('box heading "[community] %topic%"', L::Three, S::Ported, "showSuccess.php <h3>'['.\$group->getName().'] '.\$topicLabel</h3>", '[group name] %Topic%'),
                new ScreenElement('author link', L::Two, S::Ported, 'op_community_topic_link_to_member($communityTopic->getMember())'),
                new ScreenElement('created-at datetime', L::Three, S::Ported, "nl2br(op_format_date(created_at, 'XDateTimeJaBr'))", 'LocalizedDate; inline single-line'),
                new ScreenElement('article images (ul.photo, 120×120 linking to full size)', L::Two, S::Ported, '$communityTopic->getImages() ul.photo', 'the shared _images partial; each fetch is FilePolicy-gated by the board visibility'),
                new ScreenElement('article body line breaks + auto-link', L::Two, S::Ported, 'op_url_cmd(nl2br($communityTopic->getBody()))', 'x-user-text (BodyText)'),
                new ScreenElement('edit entry', L::Two, S::Ported, 'showSuccess.php div.operation > form > ul.button input Edit', 'div.operation > form (GET edit) > ul.button; deletion is reached from the edit screen'),
                new ScreenElement('comment thread (number, author, delete)', L::One, S::Ported, "include_component('communityTopicComment', 'list')"),
                new ScreenElement('comment pagination + order toggle', L::Two, S::Ported, '_list.php op_include_pager_navigation (reversible)', 'GroupTopicCommentThread: fixed size 20, older/newer + latest/oldest toggle'),
                new ScreenElement('comment datetime', L::Three, S::Ported, "nl2br(op_format_date(created_at, 'XDateTimeJaBr'))", 'LocalizedDate; inline single-line'),
                new ScreenElement('comment images', L::Three, S::Ported, '_list.php $comment->getImages() ul.photo'),
                new ScreenElement('comment body line breaks + auto-link', L::Three, S::Ported, 'op_url_cmd(nl2br($comment->getBody()))', 'x-user-text (BodyText)'),
                new ScreenElement('reply (>>N) quote link', L::Three, S::Ported, '_list.php a.reply + SnsConfig op_community_topic_plugin_community_topic_comment_reply', 'a.reply on each comment while the viewer may comment, gated by the upgraded SnsSettingKey; classic-comment-reply.js appends >>N name to the box, the bare link jumps to the form'),
                new ScreenElement('comment post form + image upload', L::One, S::Ported, "op_include_form('formCommunityTopicComment', isMultipart)", 'up to PostImages::MAX_IMAGES on one Images row; OpenPNE 3 gave each photo its own labelled row'),
                new ScreenElement('required-field markers', L::Three, S::Ported, "_partsForm.php mark_required_field + '%0% is required field.'", 'x-classic.required-mark in the label, x-classic.required-notice above the table'),
                new ScreenElement('community top-page line link', L::Two, S::Ported, "op_include_line('linkLine', link_to('community/home'))", '#linkLine to the community home, labelled [name] %Community% Top Page'),
            ],
            // newSuccess.php (PluginCommunityTopicForm) → group-topic/new.blade.php
            'new' => [
                new ScreenElement('title input', L::Two, S::Ported, 'PluginCommunityTopicForm name sfWidgetFormInput'),
                new ScreenElement('body textarea', L::Two, S::Ported, 'BaseCommunityTopicForm body', 'OpenPNE 4 adds the shared body-format toggle'),
                new ScreenElement('image upload (×3)', L::Two, S::Partial, 'photo_1..3 embedded opCommunityTopicPluginImageForm, ul#community_topic_photo_N', 'one Images row with MAX_IMAGES file inputs; OpenPNE 3 gave each photo its own labelled row inside a ul#community_topic_photo_N'),
                new ScreenElement('post button', L::Two, S::Ported, "op_include_form('formCommunityTopic') button", 'relabelled from Send, with a Cancel link beside it'),
                new ScreenElement('required-field markers', L::Three, S::Ported, "_partsForm.php mark_required_field + '%0% is required field.'", 'x-classic.required-mark in the label, x-classic.required-notice above the table'),
            ],
            // editSuccess.php (same form as new) → group-topic/edit.blade.php
            'edit' => [
                new ScreenElement('title input', L::Two, S::Ported, 'PluginCommunityTopicForm name sfWidgetFormInput'),
                new ScreenElement('body textarea', L::Two, S::Ported, 'BaseCommunityTopicForm body', 'OpenPNE 4 adds the shared body-format toggle'),
                new ScreenElement('existing image edit / delete', L::Two, S::Partial, 'communityTopic/_formEditImage.php (thumbnail + %input% + %delete%)', 'a current-images list with remove_images[] checkboxes; OpenPNE 3 let each slot be replaced in place'),
                new ScreenElement('new image upload (×3)', L::Two, S::Partial, 'photo_1..3 embedded opCommunityTopicPluginImageForm', 'one Images row with MAX_IMAGES file inputs; OpenPNE 3 gave each photo its own labelled row'),
                new ScreenElement('save button', L::Two, S::Ported, "op_include_form('formCommunityTopic') button", 'relabelled from Send, with a Cancel link beside it'),
                new ScreenElement('delete-topic box', L::Two, S::Ported, "op_include_parts('buttonBox', 'toDelete')", 'GET form to the delete confirm page'),
                new ScreenElement('required-field markers', L::Three, S::Ported, "_partsForm.php mark_required_field + '%0% is required field.'", 'x-classic.required-mark in the label, x-classic.required-notice above the table'),
            ],
            // deleteConfirmSuccess.php → group-topic/delete.blade.php
            'deleteConfirm' => [
                new ScreenElement('delete confirmation form', L::One, S::Ported, "op_include_form('deleteConfirmForm', \$form, title)", 'OpenPNE 4 adds the question paragraph OpenPNE 3 left to the box title'),
                new ScreenElement('back-to-previous line', L::Three, S::Partial, "op_include_line('backLink', link_to_function(history.back()))", 'a Cancel link to the topic instead of the JavaScript line box'),
            ],
            // communityTopicComment/deleteConfirmSuccess.php → group-topic/comment-delete.blade.php
            'communityTopicComment/deleteConfirm' => [
                new ScreenElement('delete confirmation form', L::One, S::Ported, "communityTopicComment/deleteConfirmSuccess.php op_include_form('deleteConfirmForm', \$form, url_for('communityTopic_comment_delete'))", 'the same dparts#deleteConfirmForm box, posting to group.topics.comment.delete; OpenPNE 4 adds the question paragraph and a blockquote.commentPreview of the comment'),
                new ScreenElement('box heading (the question)', L::Three, S::Partial, "deleteConfirmSuccess.php title => 'Do you really delete this comment?'", 'headed "Delete the comment", with the question moved into a paragraph inside the box'),
                new ScreenElement('delete submit button', L::Two, S::Ported, '_partsForm.php div.operation > ul.moreInfo.button > li > input.input_submit', 'same markup and Delete label; the CSRF hidden field sits under the form tag rather than inside the button li'),
                new ScreenElement('back-to-previous line', L::Three, S::Partial, "op_include_line('backLink', link_to_function(history.back()))", 'a Back link to the topic in a second button li, so the div#backLink line box is not rendered'),
                new ScreenElement('form table', L::Three, S::Missing, '_partsForm.php <table> (the confirm binds a bare sfForm, so the table holds no field row)', 'no table is rendered, so a skin rule on #deleteConfirmForm table matches nothing'),
                new ScreenElement('deletable gate', L::Two, S::Ported, 'executeDeleteConfirm forward404Unless CommunityTopicComment::isDeletable (author, or CommunityTopic::isEditable)', 'GroupTopicAccess::canDeleteComment; a Modern viewer is sent back to the topic, which confirms deletion inline'),
                new ScreenElement('post-delete redirect + flash', L::Two, S::Ported, "opCommunityTopicPluginTopicCommentActions::executeDelete redirect @communityTopic_show + flash 'The comment was deleted successfully.'", 'back to the topic with a status flash, reworded'),
            ],
        ];
    }
}
