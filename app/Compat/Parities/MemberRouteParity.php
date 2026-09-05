<?php

namespace App\Compat\Parities;

use App\Compat\CompatLevel as L;
use App\Compat\RouteMap;
use App\Compat\RouteParity;
use App\Compat\ScreenElement;
use App\Compat\ScreenStatus as S;

class MemberRouteParity extends RouteParity
{
    protected string $module = 'member';

    protected function layouts(): array
    {
        // member/config is layoutB in OpenPNE 3 (view.yml configSuccess), and the AI account page,
        // which shares its pageNav sidemenu, takes the same letter.
        return ['member.config' => 'B', 'member.config.ai.show' => 'B'];
    }

    public function maps(): array
    {
        return [
            new RouteMap('homepage', '/', 'home', 'GET', op3Action: 'home'),
            new RouteMap('member_index', '/member', 'member.index_compat', 'GET', op3Action: 'home'),
            // OpenPNE 3 kept a second /member/:id route "for BC" (member_profile), so both names map to
            // the one page.
            new RouteMap('obj_member_profile', '/member/:id', 'member.profile.show', 'GET', op3Action: 'profile'),
            new RouteMap('member_profile', '/member/:id', 'member.profile.show', 'GET', op3Action: 'profile'),
            // Own-profile aliases preserved by redirect to the canonical /member/{id}.
            new RouteMap('member_profile_mine', '/member/profile', 'member.profile.mine_compat', 'GET', op3Action: 'profile'),
            new RouteMap('member_profile_raw', '/member/profile/id/:id/*', 'member.profile.raw_compat', 'GET', op3Action: 'profile'),
            // Avatar editor: one OpenPNE 3 ANY route, split into a GET form + POST upload at the moved
            // /member/avatar (redirect in compatRedirects()).
            new RouteMap('member_config_image', '/member/image/config', 'member.avatar.edit', 'GET', op3Action: 'configImage'),
            new RouteMap('member_config_image', '/member/image/config', 'member.avatar.update', 'POST'),
            // Member search.
            new RouteMap('member_search', '/member/search', 'member.search', 'GET', op3Action: 'search'),
            // Profile editor — one OpenPNE 3 route (ANY) splits into a GET form + POST submit.
            new RouteMap('member_editProfile', '/member/edit/profile', 'member.profile.edit', 'GET', op3Action: 'editProfile'),
            new RouteMap('member_editProfile', '/member/edit/profile', 'member.profile.update', 'POST'),
            // Member config: OpenPNE 3's one ANY route becomes the GET page plus per-section POSTs, so
            // saving one section never rewrites another.
            new RouteMap('member_config', '/member/config', 'member.config', 'GET', op3Action: 'config'),
            new RouteMap('member_config', '/member/config', 'member.config.diary', 'POST'),
            new RouteMap('member_config', '/member/config', 'member.config.age', 'POST'),
            new RouteMap('member_config', '/member/config', 'member.config.surface', 'POST'),
            // OpenPNE 3 served the password change under member/config (?category=password); same URL.
            new RouteMap('member_config', '/member/config', 'member.config.password', 'POST'),
            // Email-address change request — OpenPNE 3 member/config ?category=pcAddress (mobileAddress
            // dropped); the confirmation step is an OpenPNE 4-native URL (no inventory counterpart).
            new RouteMap('member_config', '/member/config', 'member.config.email', 'POST'),
            // Withdrawal: GET /leave is kept by a redirect and the submit is the config-category POST,
            // with no POST /leave alias.
            new RouteMap('member_delete', '/leave', 'member.leave_compat', 'GET', op3Action: 'delete'),
            new RouteMap('member_delete', '/leave', 'member.config.withdrawal', 'POST'),
            // Login — Fortify owns /login; the OpenPNE 3 /member/login/* URL is preserved by a static
            // redirect (compatRedirects), and the Classic body id stays page_member_login.
            new RouteMap('login', '/member/login/*', 'login', 'GET', op3Action: 'login'),
            // Member invitation.
            new RouteMap('member_invite', '/invite', 'member.invite', 'GET', op3Action: 'invite'),
            new RouteMap('member_invite', '/invite', 'member.invite.submit', 'POST'),
        ];
    }

    public function gaps(): array
    {
        return [
            'member_logout' => 'Logout is served by Fortify at POST /logout (OpenPNE 3 also allowed GET).',
            'member_config_jsonapi' => 'The legacy config JSON API (/member/config/jsonapi) is not ported.',
            'global_changeLanguage' => 'Locale switching is POST /locale (locale.switch), not the OpenPNE 3 GET /language URL.',
        ];
    }

    public function compatRedirects(): array
    {
        return [
            // The avatar editor's OpenPNE 3 URL; redirected (not served) to the new canonical editor.
            '/member/image/config' => 'member.avatar.edit',
            // OpenPNE 3 login lived at /member/login/*; redirected to Fortify's canonical /login.
            '/member/login' => 'login',
        ];
    }

    public function screens(): array
    {
        return [
            // member/home → homeSuccess.php (gadget slots) + member/config/view.yml customize cautions
            'home' => [
                new ScreenElement('gadget zones (top / sidemenu / contents / bottom)', L::One, S::Ported, "homeSuccess.php slot('op_top') / slot('op_sidemenu') / contentsGadgets loop / slot('op_bottom')", 'GadgetService::zones("home") through partials/gadget-sections; the viewer is the subject'),
                new ScreenElement('#Layout letter from the home layout setting', L::One, S::Ported, 'memberActions::executeHome setLayout(SnsConfig home_layout) + gadget_layout_config.yml', 'GadgetService::layoutLetter — the letter follows the setting, not which zones hold gadgets'),
                new ScreenElement('information box (admin rich text)', L::Two, S::Ported, 'gadget.yml informationBox → component [default, informationBox]', 'x-gadget.information-box: div.parts.informationBox > div.body.sortHandle'),
                new ScreenElement('%friend% request caution', L::Two, S::Ported, 'member/config/view.yml homeSuccess customize cautionAboutFriendPre → friend/_cautionAboutFriendPre.php', 'links to friend.requests; OpenPNE 3 pointed at the confirmation center, which is not ported'),
                new ScreenElement('%community% administrator-transfer caution', L::Two, S::Ported, 'homeSuccess customize cautionAboutChangeAdminRequest → community/_cautionAboutChangeAdminRequest.php', 'one line per group, linking to the group home where the accept / reject banner lives'),
                new ScreenElement('unread message caution', L::Two, S::Ported, 'opMessagePlugin member/config/view.yml unreadMessage → message/_unreadMessage.php'),
                new ScreenElement('%community% join-request caution', L::Two, S::Missing, 'homeSuccess customize cautionAboutCommunityMemberPre → community/_cautionAboutCommunityMemberPre.php', 'PendingJoinRequestCounts feeds only the Modern dashboard; the Classic cautions carry no such line, so a group admin learns of a pending join only on the group page'),
                new ScreenElement('%community% sub-administrator request caution', L::Two, S::Missing, 'homeSuccess customize cautionAboutSubAdminRequest → community/_cautionAboutSubAdminRequest.php', 'OpenPNE 4 appoints a sub-admin directly (AppointSubAdmin), so there is no pending request to announce'),
                new ScreenElement('unread %diary% comment caution', L::Two, S::Missing, 'opDiaryPlugin member/config/view.yml cautionUnreadDiaryComment → diary/_cautionUnreadDiaryComment.php', 'diary comments carry no unread tracking, so the count has no source'),
                new ScreenElement('cautions rendered inside the information box body', L::Two, S::Partial, 'member/config/view.yml customize parts: [information], target: [bodyBottom]', 'home/partials/cautions renders its own div.parts.informationBox at the head of the contents zone, so a skin keyed on the gadget id (information_{id}) does not reach the caution lines'),
                new ScreenElement('gadget drag-sort and double-click fold, remembered in cookies', L::Three, S::Missing, 'homeSuccess.php javascript_tag $("#Top"/"#Left"/"#Center").sortable + foldObj + opCookie HomeGadget_{zone}_sort / HomeGadget_{id}_toggle', 'the sortHandle / partsHeading hooks are reproduced but nothing binds to them; every member sees the admin sort_order'),
                new ScreenElement('quick-link landing when no gadget is configured', L::Three, S::Ported, 'none', 'OpenPNE 4 addition: OpenPNE 3 left the column empty, so there is no kind or id to reproduce'),
            ],
            // member/profile → profileSuccess.php (gadget slots + the op_top description box)
            'profile' => [
                new ScreenElement('gadget zones (top / sidemenu / contents / bottom)', L::One, S::Ported, "profileSuccess.php slot('op_top') / slot('op_sidemenu') / contentsGadgets loop / slot('op_bottom')", 'GadgetService::zones("profile") with the page owner as subject'),
                new ScreenElement('#Layout letter from the profile layout setting', L::One, S::Ported, 'memberActions::executeProfile setLayout(SnsConfig profile_layout)'),
                new ScreenElement('own-page notice box (id informationAboutThisIsYourProfilePage)', L::Two, S::Ported, "profileSuccess.php op_include_parts('descriptionBox', 'informationAboutThisIsYourProfilePage') isSelf branch", 'member/partials/friend-link-box; the shareable profile URL and the editor link, the link following the sentence rather than embedded in it'),
                new ScreenElement('add-%friend% box under the same id', L::Two, S::Ported, "profileSuccess.php enable_friend_link branch link_to 'friend/link?id='", 'same id and descriptionBox kind; OpenPNE 4 also names the pending sent / received states, which OpenPNE 3 left blank'),
                new ScreenElement('profileListBox rows (%nickname%, age, visible values) under id profile', L::One, S::Ported, "member/_profileListBox.php op_include_parts('listBox', 'profile')", 'x-gadget.profile-list-box keeps OpenPNE 3\'s fixed id'),
                new ScreenElement('per-value visibility suffix on the owner\'s own view', L::Three, S::Missing, "_profileListBox.php appends ' (%my_friend%)' / ' (All Users on the Web)' when the viewer is the owner", 'the owner reads the same rows a member does; the flags are visible only on the profile editor'),
                new ScreenElement('textarea values line-broken and auto-linked', L::Three, S::Ported, '_profileListBox.php op_auto_link_text(nl2br($profileValue))', 'x-user-text'),
                new ScreenElement('preset values translated (country, region, choice captions, birthday)', L::Three, S::Ported, "_profileListBox.php \$culture->getCountry() / op_format_date(…, 'XShortDateJa') / __(\$profileValue)", 'MemberProfile::displayValue; the birthday renders month/day only, its year reaching the page solely through the separately-gated age'),
                new ScreenElement('member photo box (photo + name and %friend% count)', L::Two, S::Ported, 'gadget.yml memberImageBox → component [default, memberImageBox]'),
                new ScreenElement('%friend% grid with "Show all(N)" and the %my_friend% setting link', L::Two, S::Ported, "friend/_friendListBox.php op_include_parts('nineTable', 'friendList_'.gadgetId)", 'the setting link shows on your own profile only, as OpenPNE 3 gated it'),
                new ScreenElement('%community% grid with the administrator crown', L::Two, S::Ported, "community/_joinListBox.php op_include_parts('nineTable', 'communityList_'.gadgetId) + crownIds", 'crowned by the listed member\'s role, not the viewer\'s'),
                new ScreenElement('friend localNav when the page is about another member', L::Two, S::Ported, "memberActions::executeProfile sfConfig::set('sf_nav_type', 'friend')", 'markLocalNavSubject records only a non-self subject, so your own profile keeps the default nav'),
                new ScreenElement('fixed profile box when no gadget is configured', L::Three, S::Ported, 'none', 'OpenPNE 4 addition: OpenPNE 3 always drew this page from gadgets, so there is no kind or id to reproduce'),
            ],
            // member/editProfile → editProfileSuccess.php (MemberForm + MemberProfileForm in one form parts)
            'editProfile' => [
                new ScreenElement('form box id profileForm holding both forms', L::Two, S::Ported, "editProfileSuccess.php op_include_form('profileForm', array(\$memberForm, \$profileForm))"),
                new ScreenElement('%nickname% input', L::One, S::Ported, 'MemberForm name'),
                new ScreenElement('configured profile fields as rows (input / textarea / select / radio / checkbox / date / country / region)', L::One, S::Ported, 'MemberProfileForm::setConfigWidgets + opWidgetFormProfile', 'profile/_fields, shared with registration so the two cannot drift'),
                new ScreenElement('per-field visibility select beside the input', L::Two, S::Ported, "_partsForm.php opWidgetFormProfile template '<div class=\"input\">%input%</div><div class=\"publicFlag\">%public_flag%</div>'", 'div.input / div.publicFlag reproduced, so the skin still floats them'),
                new ScreenElement('date fields as separate year / month / day inputs', L::Three, S::Partial, "opFormItemGenerator FormType 'date' → opWidgetFormDate (month_format number, can_be_empty)", 'a single input[type=date]; the three-part widget, its per-part ids and its field names are not reproduced, so a skin or script keyed on them finds nothing'),
                new ScreenElement('required-field markers and the notice above the table', L::Three, S::Partial, "_partsForm.php mark_required_field '<strong>*</strong>' + '%0% is required field.'", 'profile rows carry span.required instead of strong, the %nickname% row carries no marker, and the notice line is dropped'),
                new ScreenElement('per-field help text', L::Three, S::Partial, '_partsForm.php renderRow help → div.help', 'rendered as p.help, so a skin selector written for div.help misses it'),
                new ScreenElement('per-field validation errors', L::Three, S::Ported, '$field->renderError()', 'p.error under the input'),
                new ScreenElement('submit button in div.operation > ul.moreInfo.button', L::Two, S::Ported, '_partsForm.php operation block'),
                new ScreenElement('save returns to the own profile page', L::Two, S::Partial, "executeEditProfile redirect('@member_profile_mine')", 'OpenPNE 4 returns to the editor with a status flash, so the saved page is one click further away'),
                new ScreenElement('LayoutC, no sidemenu', L::Two, S::Ported, 'member/config/view.yml declares no editProfileSuccess entry, so the global layoutC applies'),
            ],
            // member/configImage → configImageSuccess.php + _partsMemberImagesBox.php
            'configImage' => [
                new ScreenElement('memberImagesBox box id memberImageUploadBox', L::Two, S::Ported, "configImageSuccess.php op_include_parts('memberImagesBox', 'memberImageUploadBox')"),
                new ScreenElement('three photo cells, unused slots showing no_image.gif', L::Two, S::Partial, '_partsMemberImagesBox.php for ($i = 0; $i < 3; $i++)', 'OpenPNE 4 holds one avatar, so the table is a single cell and there are no empty slots to placeholder'),
                new ScreenElement('photo rendered at 180×180', L::Three, S::Partial, "op_image_tag_sf_image(\$image->getFile(), array('size' => '180x180'))", 'x-classic.image at 120px'),
                new ScreenElement('per-photo Delete link', L::Two, S::Partial, "_partsMemberImagesBox.php link_to('Delete', 'member/deleteImage?member_image_id=…') inside the bracketed link line", 'a POST Remove button under the avatar instead of the CSRF-token GET link and its bracket line'),
                new ScreenElement('Main Photo switch between slots', L::Two, S::Missing, "_partsMemberImagesBox.php link_to('Main Photo', 'member/changeMainImage?member_image_id=…') / the is_primary label", 'one avatar, so there is no second photo to promote'),
                new ScreenElement('upload form (file input + submit) inside div.block', L::One, S::Ported, "_partsMemberImagesBox.php div.block > form + \$options['form']['file'] + input_submit", 'the two p wrappers are kept so the kind stylesheet still floats the form'),
                new ScreenElement('upload notes list', L::Two, S::Partial, '_partsMemberImagesBox.php ul: 3-photo cap / max size / prohibited content', 'the 3-photo note is dropped (one avatar); size and prohibited-content notes are kept'),
                new ScreenElement('upload failure surfaced as a flash', L::Three, S::Ported, "executeConfigImage setFlash('error', …) + redirect('@member_config_image')", 'a validation error on the image field, drawn by the Classic shell alertBox'),
                new ScreenElement('LayoutC, no sidemenu', L::Two, S::Ported, 'member/config/view.yml declares no configImageSuccess entry, so the global layoutC applies'),
            ],
            // member/search → searchSuccess.php + _partsSearchResultList.php → resources/views/member/search.blade.php
            'search' => [
                new ScreenElement('last-login row on each result', L::Three, S::Missing, 'searchSuccess.php op_format_last_login_time', 'OpenPNE 4 stores no last-login time, so the row has no source'),
            ],
            // member/config → configSuccess.php (pageNav sidemenu + one category form) + configCompleteSuccess.php
            'config' => [
                new ScreenElement('category pageNav sidemenu (id pageNav)', L::Two, S::Partial, "configSuccess.php op_include_parts('pageNav', 'pageNav', array('list' => …, 'current' => \$categoryName))", 'x-member.config-sidemenu keeps the id, kind and li.current; the current entry is plain text where OpenPNE 3 kept it a link'),
                new ScreenElement('landing box when no category is selected', L::Two, S::Ported, "configSuccess.php op_include_box('configInformation', 'Please select the item that wants to be set from the menu.')", 'id and wording kept; an unrecognized ?category= lands here rather than 404-ing as OpenPNE 3 did'),
                new ScreenElement('category form box id {category}Form', L::Two, S::Ported, "configSuccess.php op_include_form(\$categoryName.'Form', \$form)", 'diaryForm / publicFlagForm / languageForm / passwordForm keep the derived id; the categories OpenPNE 4 added carry their own'),
                new ScreenElement('%diary% default public flag', L::Two, S::Ported, 'opDiaryPlugin member_config.yml diary category + MemberConfigDiaryForm::PUBLIC_FLAG', 'a select where OpenPNE 3 expanded it to radios'),
                new ScreenElement('age public flag', L::Two, S::Ported, 'member_config.yml publicFlag.age_public_flag (choices web / members / %my_friend% / private)', 'the category hides itself when the site has no birthday profile item, which OpenPNE 3 always showed'),
                new ScreenElement('profile page public flag', L::Two, S::Ported, 'member_config.yml publicFlag.profile_page_public_flag (All Users on the Web / All Members) under sns_config is_allow_config_public_flag_profile_page', 'members.profile_visibility, an Open / Members select in OpenPNE 3\'s order, offered only while SnsSettingKey::ProfileVisibilityPolicy is member_choice — the same admin gate OpenPNE 3 unset the field under; it shares OpenPNE 3\'s one publicFlagForm box with the age select but posts on its own form and save button'),
                new ScreenElement('password change (current + new + confirm)', L::One, S::Ported, 'member_config.yml password category + MemberConfigPasswordForm (now_password / password / password_confirm)', 'field names not preserved; the save also rotates remember_token and drops the member\'s other sessions'),
                new ScreenElement('PC mail address change', L::One, S::Ported, 'member_config.yml pcAddress category + MemberConfigPcAddressForm', 'served as the email category; ?category=pcAddress is redirected to it, and the change commits only when the mailed confirmation link is opened'),
                new ScreenElement('mail-address confirmation step', L::Two, S::Ported, "configCompleteSuccess.php op_include_form('formConfigComplete', …) — new value row + password re-auth", 'an OpenPNE 4-native token URL (member.config.email.confirm) instead of member/configComplete?token=…&id=…&type='),
                new ScreenElement('mobile mail address change', L::Two, S::Missing, 'member_config.yml mobileAddress category + MemberConfigMobileAddressForm', 'OpenPNE 4 carries no mobile address, so the category is deliberately dropped'),
                new ScreenElement('access block category', L::One, S::Ported, 'member_config.yml accessBlock category + MemberConfigAccessBlockForm (increased member-id inputs)', '/member/config?category=accessBlock 302s to the canonical Block list, which manages the same relation as its own screen'),
                new ScreenElement('notification mail opt-ins', L::Two, S::Partial, 'member_config.yml mail category + MemberConfigMailForm (Receive / Don\'t Receive per NotificationMail, plus daily_news frequency)', 'the notification category covers the per-kind opt-ins; the daily-news digest and its weekly / everyday frequency choice have no OpenPNE 4 counterpart'),
                new ScreenElement('language and time zone', L::Two, S::Partial, 'member_config.yml language category (language + time_zone selects)', 'language is ported through the shared locale switch; there is no per-member time zone, so the setting has nothing to write'),
                new ScreenElement('secret question and answer', L::Two, S::Missing, 'opAuthMailAddressPlugin member_config.yml secretQuestion category + MemberConfigSecretQuestionForm', 'password recovery is an emailed reset link, so there is no secret question to store'),
                new ScreenElement('external-connection pageNav box (id connection)', L::Two, S::Missing, "configSuccess.php op_include_parts('pageNav', 'connection', …) — Connecting with External Application / JSON API / OpenID", 'none of the three has an OpenPNE 4 counterpart (the JSON API is recorded in gaps()), so the whole box is absent'),
                new ScreenElement('withdrawal pageNav box (id navForDelete)', L::Two, S::Partial, "configSuccess.php op_include_parts('pageNav', 'navForDelete', array(link_to('Delete your %sns_name% account', '@member_delete')))", 'withdrawal is an entry in the main category list, so the separate box, its id and the SNS-named wording are gone'),
                new ScreenElement('save flash notice', L::Three, S::Ported, "executeConfig setFlash('notice', \$form->getCompleteMessage()) + redirect back to the category", "one 'Settings updated.' for every section where OpenPNE 3 let each form word its own"),
                new ScreenElement('LayoutB with the pageNav in the sidemenu', L::Two, S::Ported, 'member/config/view.yml configSuccess layout: layoutB'),
            ],
            // member/invite → inviteInput.php (+ inviteSuccess.php / inviteError.php)
            'invite' => [
                new ScreenElement('form box id inviteForm', L::Two, S::Partial, "inviteInput.php op_include_form('inviteForm', \$form, array('title' => 'Invite a friend to %sns_name%'))", 'the id and kind are kept; the heading drops the SNS name ("Invite a new member")'),
                new ScreenElement('mail address input', L::One, S::Ported, 'InviteForm mail_address'),
                new ScreenElement('optional message textarea', L::Two, S::Ported, "InviteForm message (label 'Message(Arbitrary)')"),
                new ScreenElement('two-column form table', L::Two, S::Partial, '_partsForm.php table > tr > th/td', 'the fields are a dl > dt/dd list under a div.block intro, so a skin styling the form table does not reach them'),
                new ScreenElement('submit button in div.operation > ul.moreInfo.button', L::Two, S::Ported, '_partsForm.php operation block'),
                new ScreenElement('address that already has an account is refused', L::Two, S::Ported, 'InviteForm::validate → validateAddress (MemberConfig uniqueness)', 'answered as a status message on the form rather than a field error; the caller is authenticated, so this is not the enumeration leak the anonymous entry must avoid'),
                new ScreenElement('pending-invitation list (id invitelistForm) with the invite date', L::Two, S::Missing, 'inviteInput.php div.dparts.recentList#invitelistForm over Member::getInvitingMembers + InvitelistForm', 'OpenPNE 4 keeps no member-facing list of outstanding invitations'),
                new ScreenElement('bulk delete of pending invitations', L::Two, S::Missing, 'inviteInput.php InvitelistForm checkboxes + Delete submit', 'follows the list above — with nothing listed there is nothing to select'),
                new ScreenElement('sent confirmation screen', L::Two, S::Partial, "inviteSuccess.php op_include_box('inviteForm', 'Sent.')", 'OpenPNE 4 redirects back to the form with a status flash, so the separate confirmation box never renders'),
                new ScreenElement('not-permitted error screen + back link', L::Two, S::Partial, "inviteError.php op_include_box('inviteForm', 'The invitation has not been permitted.') + backLink link_to_function history.back()", 'EnsureMemberInviteAllowed 404s the URL for a mode that forbids member invites, so the error box and its line are never reached'),
                new ScreenElement('LayoutC, no sidemenu', L::Two, S::Ported, 'member/config/view.yml declares no inviteInput entry, so the global layoutC applies'),
            ],
            // member/delete → deleteInput.php (+ deleteError.php); OpenPNE 4 serves it as the withdrawal config category
            'delete' => [
                new ScreenElement('GET /leave reaches the withdrawal screen', L::One, S::Ported, 'routing.yml member_delete /leave (sf_method get,post)', 'a 302 to member.config?category=withdrawal, so the bookmarked URL lands on the screen rather than serving it'),
                new ScreenElement('POST /leave submit', L::Two, S::Missing, 'routing.yml member_delete accepts the submit on the same URL', 'the submit moved to the config-category POST (member.config.withdrawal); no POST /leave alias exists'),
                new ScreenElement('information box (are you sure / enter your password)', L::Two, S::Partial, "deleteInput.php op_include_box('informationThisPage', 'Do you delete your %sns_name% account?' + 'Please input your password …')", 'one sentence inside the withdrawal form box; the separate box, its id and the SNS name are gone'),
                new ScreenElement('password re-auth form box (id passwordForm)', L::One, S::Partial, "deleteInput.php op_include_form('passwordForm', \$form, array('title' => 'Delete your %sns_name% account', 'url' => 'member/delete'))", 'the box is member_config_withdrawal on the config page, so neither the id nor the SNS-named heading is preserved'),
                new ScreenElement('password field', L::One, S::Ported, 'opPasswordForm password', 'current_password:member; OpenPNE 4 adds an explicit confirmation checkbox beside it'),
                new ScreenElement('submit button in div.operation > ul.moreInfo.button', L::Two, S::Ported, '_partsForm.php operation block'),
                new ScreenElement('the primary member cannot withdraw', L::Two, S::Partial, "executeDelete returns sfView::ERROR for member id 1 → deleteError.php op_include_box('error', 'You can not delete your account.') + backLink", 'WithdrawalRequest::authorize answers 403 and the category stays visible, so the error box and its back link never render'),
                new ScreenElement('withdrawal logs out and returns to login with a notice', L::One, S::Ported, "executeDelete delete + logout + setFlash('notice') + redirect('member/login')", 'also purges the member\'s other sessions, which OpenPNE 3 left running'),
                new ScreenElement('withdrawal mails to the administrator and the leaving member', L::Three, S::Ported, "sendDeleteAccountMail: deleteAccountMail to admin_mail_address + the 'leave' template to the member", 'MemberWithdrawn → NotifyMemberWithdrawn'),
                new ScreenElement('LayoutC, no sidemenu', L::Two, S::Partial, 'member/config/view.yml declares no deleteInput entry, so the global layoutC applies', 'the redirect target is the member-config page, which is layoutB with the category pageNav in the sidemenu'),
            ],
            // member/login → _partsLogin.php (.loginForm) → resources/views/auth/login.blade.php
            'login' => [
                new ScreenElement('mail address + password inputs', L::One, S::Ported, 'opAuthLoginFormMailAddress (mail_address, password)', 'field names not preserved (email/password, Level 3)'),
                new ScreenElement('remember-me checkbox', L::Two, S::Ported, 'opAuthLoginForm is_remember_me', 'field name not preserved (remember, Level 3) — Fortify reads it'),
                new ScreenElement('login button', L::Two, S::Ported, '_partsLogin input_submit'),
                new ScreenElement('password reminder link', L::One, S::Ported, 'link_to help_login_error_action', 'links to /forgot-password (password.request)'),
                new ScreenElement('self-registration link', L::Two, S::Ported, 'link_to self_invite_action', 'shown when open registration is on, mirroring OpenPNE 3\'s invite_mode==2 + enable_registration gate'),
                new ScreenElement('login gadget zones (top/side/contents/bottom)', L::Three, S::Ported, 'op_login_gadget_list', 'GadgetService login context renders configured zones; fixed single-column form is the empty state'),
            ],
        ];
    }

    public function acknowledgesGlobalFallback(): bool
    {
        return true;
    }
}
