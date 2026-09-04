<?php

use App\Captcha\Captcha;
use App\Features\AiAccount\AiAccountController;
use App\Features\Auth\RegistrationController;
use App\Features\Block\BlockController;
use App\Features\Compose\EditorPreferenceController;
use App\Features\Compose\PreviewController;
use App\Features\Diary\DiaryCommentController;
use App\Features\Diary\DiaryController;
use App\Features\DirectMessage\ConversationController;
use App\Features\DirectMessage\DirectMessageController;
use App\Features\Friend\FriendController;
use App\Features\Group\GroupController;
use App\Features\Group\GroupMemberManageController;
use App\Features\GroupEvent\GroupEventCommentController;
use App\Features\GroupEvent\GroupEventController;
use App\Features\GroupTalk\GroupTalkController;
use App\Features\GroupTalk\GroupTalkReactionController;
use App\Features\GroupTopic\GroupTopicCommentController;
use App\Features\GroupTopic\GroupTopicController;
use App\Features\Home\HomeController;
use App\Features\Home\HomeIssueController;
use App\Features\Home\UnreadCountsController;
use App\Features\Member\EmailChangeLinkController;
use App\Features\Member\InviteController;
use App\Features\Member\MemberAvatarController;
use App\Features\Member\MemberConfigController;
use App\Features\Member\MemberMfaController;
use App\Features\Member\MemberSearchController;
use App\Features\Member\MfaResetLinkController;
use App\Features\Notifications\NotificationCenterController;
use App\Features\Notifications\NotificationFeedController;
use App\Features\Notifications\NotificationSettingsController;
use App\Features\Notifications\PushSubscriptionController;
use App\Features\Profile\ProfileController;
use App\Features\Timeline\TimelineController;
use App\Files\AppIcon;
use App\Http\Controllers\Admin\AdminFileController;
use App\Http\Controllers\AppIconController;
use App\Http\Controllers\BannerImageController;
use App\Http\Controllers\CustomizingCssController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\LinkCardImageController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\PublicFileController;
use App\Http\Middleware\AsBackgroundFetch;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureMemberInviteAllowed;
use App\Http\Middleware\EnsureOpenRegistration;
use App\Http\Middleware\EnsureWebPublicDiaryEnabled;
use App\Http\Middleware\NoReferrer;
use App\Http\Middleware\SetLocale;
use App\Models\Member;
use App\Support\BrandColor;
use App\Support\ClassicErrorPage;
use App\Support\GuestLoginRedirect;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\NewPasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// OpenPNE 3 member/home, guest-reachable, so it carries auth.session itself: a session whose
// password hash is stale must not keep reading member content.
Route::get('/', [HomeController::class, 'index'])->middleware('auth.session')->name('home');

// OpenPNE 3 member_index alias (/member) for the same member/home portal.
Route::get('/member', fn () => redirect('/'))->name('member.index_compat');

Route::post('/locale', function (Request $request) {
    $locale = (string) $request->input('locale');
    if (in_array($locale, SetLocale::SUPPORTED_LOCALES, strict: true)) {
        $request->session()->put('locale', $locale);
        // Written to both the column and the session so the two never disagree for a member.
        $member = $request->user('member');
        if ($member instanceof Member) {
            $member->forceFill(['locale' => $locale])->save();
        }
    }

    // A full page load: the React i18n provider reads `locale` only at app boot, so an XHR-followed
    // 302 would leave it on the old locale.
    $target = url()->previous();
    if ($request->header('X-Inertia')) {
        return Inertia::location($target);
    }

    return redirect($target);
})->name('locale.switch');

// Under /admin so it runs on the admin session store, where the panel's CSRF token validates and
// its SetLocale:session reads.
Route::post('/admin/locale/session', function (Request $request) {
    $locale = (string) $request->input('locale');
    if (in_array($locale, SetLocale::SUPPORTED_LOCALES, strict: true)) {
        // Never members.locale: a co-logged-in member's durable preference must not change with the
        // panel language.
        $request->session()->put('locale', $locale);
    }

    return response()->noContent();
})->name('locale.switch.session');

// Public, yet auth.session: without it a session whose password hash is stale keeps a non-null
// viewer here, and every gate downstream reads that viewer's clearance.
Route::middleware('auth.session')->group(function () {
    Route::get('/member/{member}', [ProfileController::class, 'show'])
        ->whereNumber('member')->name('member.profile.show');
    // OpenPNE 3 member_profile_raw: its trailing splat matched extra segments, so {tail} is captured
    // and ignored.
    Route::get('/member/profile/id/{member}/{tail?}', fn (int $member) => redirect()->route('member.profile.show', ['member' => $member]))
        ->whereNumber('member')->where('tail', '.*')->name('member.profile.raw_compat');
});

// Fortify's own routes are off: its /user/two-factor-* endpoints would bypass this app's re-auth
// and session-revocation contract (docs/internals/security.md), so the routes used are declared here.
Route::middleware([NoReferrer::class, 'throttle:password-reset'])->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])
        ->middleware('guest:member')->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware(['guest:member', 'throttle:login'])->name('login.store');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
        ->middleware('auth:member')->name('logout');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])
        ->middleware('guest:member')->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('guest:member')->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])
        ->middleware('guest:member')->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])
        ->middleware('guest:member')->name('password.update');

    // The challenge GET is deliberately unthrottled (vendor parity): a refresh or back-navigation
    // render must not consume the code-guess budget before the member has typed anything.
    Route::get('/two-factor-challenge', [TwoFactorAuthenticatedSessionController::class, 'create'])
        ->middleware('guest:member')->name('two-factor.login');
    Route::post('/two-factor-challenge', [TwoFactorAuthenticatedSessionController::class, 'store'])
        ->middleware(['guest:member', 'throttle:two-factor'])->name('two-factor.login.store');
});

// OpenPNE 3 member/login.
Route::get('/member/login/{tail?}', fn () => redirect()->route('login'))
    ->where('tail', '.*')->name('member.login_compat');

// OpenPNE 3 GET /leave; its POST is deliberately not carried, since the submit is member.config.withdrawal.
Route::get('/leave', fn () => redirect()->route('member.config', ['category' => 'withdrawal']))
    ->name('member.leave_compat');

// OpenPNE 3 sites carrying the notification extension served its settings at
// member/configNotification (a global-fallback URL, no named route); OpenPNE 4 serves them as
// the member-config notification category.
Route::get('/member/configNotification', fn () => redirect()->route('member.config', ['category' => 'notification']))
    ->name('member.config_notification_compat');

// Neither guest- nor auth-restricted (the link may be opened on another device), and the change
// happens on POST so a mail scanner's prefetch cannot consume the token.
Route::get('/member/config/email/confirm/{token}', [EmailChangeLinkController::class, 'confirmEmailForm'])
    ->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1')->name('member.config.email.confirm');
Route::post('/member/config/email/confirm/{token}', [EmailChangeLinkController::class, 'confirmEmail'])
    ->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1')->name('member.config.email.confirm.submit');

// Same shape as confirmation, with its own token, so the old-address holder can void a change
// without signing in.
Route::get('/member/config/email/cancel/{token}', [EmailChangeLinkController::class, 'cancelEmailForm'])
    ->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1')->name('member.config.email.cancel');
Route::post('/member/config/email/cancel/{token}', [EmailChangeLinkController::class, 'cancelEmail'])
    ->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1')->name('member.config.email.cancel.submit');

// Same public shape as the email links, plus a per-token limiter on the POST so distributed password
// guessing cannot pool onto one link.
Route::get('/member/mfa/reset/{token}', [MfaResetLinkController::class, 'form'])
    ->where('token', '[A-Za-z0-9]{40}')->middleware([NoReferrer::class, 'throttle:30,1'])->name('member.mfa.reset');
Route::post('/member/mfa/reset/{token}', [MfaResetLinkController::class, 'reset'])
    ->where('token', '[A-Za-z0-9]{40}')->middleware([NoReferrer::class, 'throttle:30,1', 'throttle:mfa-reset'])->name('member.mfa.reset.submit');

// OpenPNE 3 opAuthMailAddress: its id + token scheme cannot be honored by Fortify, so both entry
// points restart at the request form.
Route::get('/opAuthMailAddress/passwordRecovery', fn () => redirect()->route('password.request'))
    ->name('auth.password_recovery_compat');
Route::get('/opAuthMailAddress/passwordRecoveryComplete', fn () => redirect()->route('password.request'))
    ->name('auth.password_recovery_complete_compat');

Route::middleware(['guest', NoReferrer::class, EnsureOpenRegistration::class])->controller(RegistrationController::class)->group(function () {
    Route::get('/register', 'requestForm')->name('register');
    Route::post('/register', 'request')->middleware('throttle:register-email')->name('register.request');
    Route::get('/register/sent', 'sent')->name('register.sent');
});
// The completion half sits outside EnsureOpenRegistration because an invited member must finish in
// invite or admin_only mode; the controller re-checks the mode against the token's origin.
Route::middleware(['guest', NoReferrer::class])->controller(RegistrationController::class)->group(function () {
    Route::get('/register/{token}', 'form')->where('token', '[A-Za-z0-9]{40}')
        ->middleware('throttle:register-complete')->name('register.form');
    Route::post('/register/{token}', 'register')->where('token', '[A-Za-z0-9]{40}')
        ->middleware('throttle:register-complete')->name('register.complete');
});

// Public: someone deciding whether to join reads them before they have an account.
Route::get('/terms', [PolicyController::class, 'terms'])->name('policy.terms');
Route::get('/privacy', [PolicyController::class, 'privacy'])->name('policy.privacy');
Route::get('/userAgreement', fn () => redirect()->route('policy.terms', [], 301))->name('policy.terms_compat');
Route::get('/default/userAgreement', fn () => redirect()->route('policy.terms', [], 301))->name('policy.terms.default_compat');
Route::get('/privacyPolicy', fn () => redirect()->route('policy.privacy', [], 301))->name('policy.privacy_compat');
Route::get('/default/privacyPolicy', fn () => redirect()->route('policy.privacy', [], 301))->name('policy.privacy.default_compat');

Route::get('/altcha/challenge', fn (Captcha $captcha) => response()->json($captcha->challenge()))
    ->middleware(['throttle:60,1', AsBackgroundFetch::class])->name('altcha.challenge');

// Standalone display with a site-wide scope: without it iOS overlays a title bar on every in-app
// navigation.
Route::get('/manifest.webmanifest', fn () => response()->json([
    'name' => sns_name(),
    'short_name' => sns_name(),
    'start_url' => '/',
    'scope' => '/',
    'display' => 'standalone',
    'background_color' => '#ffffff',
    'theme_color' => brand_color() ?? BrandColor::DEFAULT,
    'icons' => [
        ['src' => app_icon_url(192), 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => app_icon_url(512), 'sizes' => '512x512', 'type' => 'image/png'],
    ],
], options: JSON_UNESCAPED_SLASHES)->header('Content-Type', 'application/manifest+json'))->name('webmanifest');

// Both segments are constrained here: an unlisted size must 404 before a typed int parameter rejects
// it as a 500.
Route::get('/app-icon/{token}/{size}.png', [AppIconController::class, 'show'])
    ->where(['token' => '[A-Za-z0-9_.-]+', 'size' => implode('|', AppIcon::SIZES)])
    ->name('app_icon');

// OpenPNE 3 /cache/css/customizing.css, served from the database rather than a written cache file.
Route::get('/cache/css/customizing.css', [CustomizingCssController::class, 'show'])->name('design.customizing_css');

// Public, as OpenPNE 3 banners show to guests; the controller serves banner-owned files only.
Route::get('/banner/image/{file:name}', [BannerImageController::class, 'show'])->name('banner.image');

// No login; the controller serves only files explicitly marked public.
Route::get('/file/public/{file:name}', [PublicFileController::class, 'show'])->name('file.public');

// Gated by the admin guard inside the controller and deliberately bypassing FilePolicy: an
// administrator may inspect any file.
Route::get('/admin/file/{file:name}/raw', [AdminFileController::class, 'show'])->name('admin.file.raw');

// Login-free so a web-public page's bytes render for the guest reading it, with FilePolicy gating
// every fetch by the owning entity; auth.session for the same reason as the profile route.
Route::middleware('auth.session')->group(function () {
    Route::get('/file/{file:name}', [FileController::class, 'show'])->name('file.show');

    // The size must be whitelisted (ImageTransform), so arbitrary sizes 404.
    Route::get('/cache/img/{format}/{geometry}/{name}.{ext}', [ImageController::class, 'show'])
        ->where([
            'format' => 'jpg|png|gif|webp',
            'geometry' => 'w[0-9]*_h[0-9]*(_sq)?',
            // `.` is admitted because OpenPNE 3 file names allow [\w._-]; the greedy match still binds
            // the trailing `.{ext}`.
            'name' => '[A-Za-z0-9_.-]+',
            'ext' => 'jpg|png|gif|webp',
        ])
        ->name('image.show');
});

// Authorised through the post named in the URL, not the file: the same card can sit under a
// world-readable diary and a private one at once.
Route::middleware('auth.session')->group(function () {
    Route::get('/linkCard/{context}/{record}/img/{format}/{geometry}/{name}.{ext}', [LinkCardImageController::class, 'show'])
        ->where([
            // A closed list, not a class name: the URL may choose which post is consulted, never
            // which model the app resolves.
            'context' => 'diary|topic|event|timeline|talk|diaryComment|topicComment|eventComment',
            'record' => '[0-9]+',
            'format' => 'jpg|png|gif|webp',
            'geometry' => 'w[0-9]*_h[0-9]*(_sq)?',
            'name' => '[A-Za-z0-9_.-]+',
            'ext' => 'jpg|png|gif|webp',
        ])
        ->name('linkCard.image');
});

// Guest-reachable as in OpenPNE 3 (`is_secure: false`); the feature gate precedes the web-public
// gate so a switched-off diary 404s the guest too.
Route::middleware(['auth.session', EnsureFeatureEnabled::class.':diary', EnsureWebPublicDiaryEnabled::class])->group(function () {
    // OpenPNE 3 diary_index forwarded /diary to the list action; redirected here (URL preserved,
    // canonical URL is /diary/list).
    Route::get('/diary', fn () => redirect()->route('diary.list'))->name('diary.index_compat');

    // A guest must not learn whether an id belongs to a member, so a binding failure answers exactly
    // as an author with no web-public diary does.
    $memberMissing = function (Request $request) {
        if ($request->user() === null) {
            return GuestLoginRedirect::response();
        }

        throw new NotFoundHttpException;
    };

    Route::prefix('diary')->controller(DiaryController::class)->group(function () use ($memberMissing) {
        // Literal-prefix routes must precede the {diary} wildcard.
        Route::get('/search', 'search')->name('diary.search');
        Route::get('/list', 'list')->name('diary.list');
        Route::get('/listMember/{member?}', 'listMember')->whereNumber('member')->missing($memberMissing)->name('diary.list_member');
        // Calendar archive: same listMember view narrowed to a month or day.
        Route::get('/listMember/{member}/{year}/{month}/{day?}', 'listMemberArchive')
            ->where(['member' => '[0-9]+', 'year' => '[12][0-9]{3}', 'month' => '0?[1-9]|1[0-2]', 'day' => '0?[1-9]|[12][0-9]|3[01]'])
            ->missing($memberMissing)
            ->name('diary.list_member.archive');
        Route::get('/{diary}', 'show')->whereNumber('diary')->name('diary.show');
    });
});

// OpenPNE 3 member/invite.
Route::middleware(['auth', 'auth.session', EnsureMemberInviteAllowed::class])->controller(InviteController::class)->group(function () {
    Route::get('/invite', 'show')->name('member.invite');
    Route::post('/invite', 'submit')->middleware('throttle:member-invite')->name('member.invite.submit');
});

// auth.session (AuthenticateSession) drops a logged-in session on its next protected request once
// the member's password hash changes — a best-effort cross-driver fallback; the reset itself purges
// database-driver sessions outright (see ResetMemberPassword).
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::get('/dashboard', [HomeController::class, 'dashboard'])->name('dashboard');

    // Shell-wide, not notification-owned, so it carries no unit gate (docs/internals/notifications.md).
    Route::get('/unread-counts', [UnreadCountsController::class, 'show'])->name('unread.counts');

    // No unit owns the issues: each band inside one is gated by its own unit as it renders
    // (docs/internals/home-issues.md).
    Route::get('/home/issues', [HomeIssueController::class, 'index'])->name('home.issues');
    Route::get('/home/{year}/{month}/{day}', [HomeIssueController::class, 'show'])
        ->where(['year' => '[12][0-9]{3}', 'month' => '0?[1-9]|1[0-2]', 'day' => '0?[1-9]|[12][0-9]|3[01]'])
        ->name('home.issue');

    // Its own limiter: a keystroke-driven endpoint fires far more often than a post.
    Route::post('/compose/preview', [PreviewController::class, 'preview'])->middleware('throttle:preview')->name('compose.preview');
    // Unthrottled, like the /member/config/* preference POSTs.
    Route::post('/compose/editor', [EditorPreferenceController::class, 'update'])->name('compose.editor');
    // Modern-only, so it renders Inertia directly rather than through a surface twin.
    Route::get('/groups/recent', [HomeController::class, 'groupActivity'])
        ->middleware(EnsureFeatureEnabled::class.':group')->name('group.recent');

    Route::prefix('notifications')->controller(NotificationFeedController::class)->group(function () {
        Route::get('/', 'index')->name('notifications.index');
        Route::post('/read-all', 'readAll')->name('notifications.readAll');
        Route::post('/{notification}/open', 'open')->whereUuid('notification')->name('notifications.open');
    });

    // The throttle bounds the churn one member can cause reaching the controller's per-member cap.
    Route::post('/push/subscriptions', [PushSubscriptionController::class, 'store'])
        ->middleware('throttle:30,1')->name('push.subscriptions.store');
    Route::post('/push/subscriptions/delete', [PushSubscriptionController::class, 'destroy'])
        ->name('push.subscriptions.destroy');

    // The Classic header panel: its rows, and the two decisions OpenPNE 3 let a member take without
    // leaving the page.
    Route::prefix('notifications/center')->controller(NotificationCenterController::class)->group(function () {
        Route::get('/', 'panel')->name('notifications.center');
        Route::get('/counts', 'counts')->name('notifications.center.counts');
        // Friend decisions taken from the panel: friend-owned endpoints outside the /friend prefix,
        // so they carry the feature gate individually (the panel itself is not friend-owned).
        Route::post('/{notification}/friend-accept', 'acceptFriend')->whereUuid('notification')
            ->middleware(['throttle:friend-request', EnsureFeatureEnabled::class.':friend'])->name('notifications.center.friendAccept');
        Route::post('/{notification}/friend-reject', 'rejectFriend')->whereUuid('notification')
            ->middleware(EnsureFeatureEnabled::class.':friend')->name('notifications.center.friendReject');
    });

    Route::prefix('friend')->middleware(EnsureFeatureEnabled::class.':friend')->controller(FriendController::class)->group(function () {
        Route::get('/list', 'list')->name('friend.list');
        // OpenPNE 3's friend/manage: the member's own roster with an unlink column.
        Route::get('/manage', 'manage')->name('friend.manage');
        // The pending-request queues (received / sent) — no OpenPNE 3 page behind this; its
        // requests were answered from the notification center and home cautions.
        Route::get('/requests', 'requests')->name('friend.requests');
        Route::get('/link', 'showLink')->name('friend.link.show');
        Route::post('/link', 'submitLink')->middleware('throttle:friend-request')->name('friend.link');
        Route::post('/accept', 'submitAccept')->middleware('throttle:friend-request')->name('friend.accept');
        Route::post('/reject', 'submitReject')->name('friend.reject');
        // A scalar id, not an implicit binding: OpenPNE 3 answers a vanished member the same way
        // it answers a non-friend — a notice on the manage page — and a binding would 404 first.
        Route::get('/unlink/{member}', 'showUnlink')->whereNumber('member')->name('friend.unlink.show');
        Route::post('/unlink/{member}', 'submitUnlink')->whereNumber('member')->name('friend.unlink.submit');
    });

    Route::prefix('block')->controller(BlockController::class)->group(function () {
        Route::get('/list', 'list')->name('block.list');
        Route::get('/add', 'showAdd')->name('block.add.show');
        Route::post('/add', 'submitAdd')->name('block.add');
        Route::get('/remove/{member}', 'showRemove')->name('block.remove.show');
        Route::post('/remove/{member}', 'submitRemove')->name('block.remove.submit');
    });

    // The write half; the guest-reachable read screens are the group above.
    Route::prefix('diary')->middleware(EnsureFeatureEnabled::class.':diary')->controller(DiaryController::class)->group(function () {
        // Two gates: the lens is the friend unit, and friendships survive its toggle, so this deep
        // link would otherwise keep serving it.
        Route::get('/listFriend', 'listFriend')->middleware(EnsureFeatureEnabled::class.':friend')->name('diary.list_friend');
        Route::get('/new', 'new')->name('diary.new');
        Route::post('/create', 'store')->middleware('throttle:posting')->name('diary.store');
        Route::get('/edit/{diary}', 'edit')->whereNumber('diary')->name('diary.edit');
        Route::post('/update/{diary}', 'update')->whereNumber('diary')->middleware('throttle:posting')->name('diary.update');
        Route::get('/deleteConfirm/{diary}', 'showDelete')->whereNumber('diary')->name('diary.delete.show');
        Route::post('/delete/{diary}', 'delete')->whereNumber('diary')->name('diary.delete');
    });

    // OpenPNE 3 diaryComment.
    Route::controller(DiaryCommentController::class)->middleware(EnsureFeatureEnabled::class.':diary')->group(function () {
        // OpenPNE 3 @diary_comment_history: the diaries the viewer commented on, by last comment.
        Route::get('/diary/comment/history', 'history')->name('diary.comment.history');
        Route::post('/diary/{diary}/comment/create', 'store')->whereNumber('diary')->middleware('throttle:posting')->name('diary.comment.store');
        Route::get('/diary/comment/deleteConfirm/{comment}', 'showDelete')->whereNumber('comment')->name('diary.comment.delete.show');
        Route::post('/diary/comment/delete/{comment}', 'delete')->whereNumber('comment')->name('diary.comment.delete');
    });

    Route::controller(DiaryCommentController::class)->group(function () {
        // No GET delete-confirm twin — Modern confirms delete inline (Radix AlertDialog).
    });

    // OpenPNE 3 opTimelinePlugin.
    Route::controller(TimelineController::class)->middleware(EnsureFeatureEnabled::class.':timeline')->group(function () {
        Route::get('/timeline', 'index')->name('timeline.index');
        Route::get('/timeline/rows', 'indexRows')->name('timeline.index.rows');
        Route::get('/member/{member}/timeline', 'member')->whereNumber('member')->name('timeline.member');
        Route::get('/member/{member}/timeline/rows', 'memberRows')->whereNumber('member')->name('timeline.member.rows');
        Route::get('/timeline/new', 'new')->name('timeline.new');
        // What the compose form's @mention picker reads (JSON), on a keystroke-rate limiter like the preview's.
        Route::get('/timeline/mention-candidates', 'mentionCandidates')->middleware('throttle:mention-search')->name('timeline.mention_candidates');
        // The tag is percent-encoded in the URL and reaches the action decoded.
        Route::get('/timeline/tag/{tag}', 'tag')->name('timeline.tag');
        Route::get('/timeline/tag/{tag}/rows', 'tagRows')->name('timeline.tag.rows');
        Route::post('/timeline/create', 'store')->middleware('throttle:posting')->name('timeline.store');
        Route::get('/timeline/deleteConfirm/{timelinePost}', 'showDelete')->whereNumber('timelinePost')->name('timeline.delete.show');
        Route::post('/timeline/delete/{timelinePost}', 'delete')->whereNumber('timelinePost')->name('timeline.delete');
        Route::post('/timeline/{timelinePost}/reply', 'storeReply')->whereNumber('timelinePost')->middleware('throttle:posting')->name('timeline.reply.store');
        // What the Classic row's 以前のコメントを見る reads: the thread's whole reply list as an HTML
        // fragment, gated exactly as the thread page is.
        Route::get('/timeline/{timelinePost}/replies', 'replies')->whereNumber('timelinePost')->name('timeline.replies');
        Route::get('/timeline/{timelinePost}', 'show')->whereNumber('timelinePost')->name('timeline.show');
    });

    Route::controller(TimelineController::class)->group(function () {
        // No GET delete-confirm twin — Modern confirms delete inline (Radix AlertDialog).
    });

    // The community timeline's compose and POST routes are deliberately gone: a redirect that dropped
    // a member's draft would be worse than a 404 (docs/internals/group-talk.md).
    $talkRedirect = fn (Request $request, int $group) => redirect()
        ->route('group.talk.show', ['group' => $group] + $request->query());

    // Gated on groupTalk, not timeline: the destination is a talk screen, so a site with the timeline
    // unit off must still honour these URLs.
    Route::middleware(EnsureFeatureEnabled::class.':groupTalk')->group(function () use ($talkRedirect) {
        Route::get('/groups/{group}/timeline', $talkRedirect)->whereNumber('group')->name('group.talk.timeline_compat');
        Route::get('/community/{group}/timeline', $talkRedirect)->whereNumber('group')->name('group.talk.legacy_compat');
        Route::get('/timeline/community/id/{group}', $talkRedirect)->whereNumber('group')->name('group.talk.legacy_fallback_compat');
    });

    // OpenPNE 3 linked the single-post permalink at /timeline/show/id/:id (reached via the global
    // /:module/:action fallback); preserve that URL by redirecting to the canonical timeline.show.
    Route::get('/timeline/show/id/{timelinePost}', fn (int $timelinePost) => redirect()->route('timeline.show', ['timelinePost' => $timelinePost]))
        ->whereNumber('timelinePost')->middleware(EnsureFeatureEnabled::class.':timeline')->name('timeline.show.compat');

    // OpenPNE 3's SNS-wide timeline lived at /sns/timeline; preserve that URL by redirecting to the
    // canonical home feed at /timeline.
    Route::get('/sns/timeline', fn () => redirect()->route('timeline.index'))
        ->middleware(EnsureFeatureEnabled::class.':timeline')->name('timeline.index.compat');

    // OpenPNE 3 member/config; each section saves on its own POST so one change never rewrites another.
    Route::get('/member/config', [MemberConfigController::class, 'show'])->name('member.config');
    // Diary-owned, outside the /diary prefix (it is a member-config section), so it carries the gate itself.
    Route::post('/member/config/diary', [MemberConfigController::class, 'updateDiary'])
        ->middleware(EnsureFeatureEnabled::class.':diary')->name('member.config.diary');
    Route::post('/member/config/age', [MemberConfigController::class, 'updateAge'])->name('member.config.age');
    Route::post('/member/config/surface', [MemberConfigController::class, 'updateSurface'])->name('member.config.surface');
    // The layout choice (docs/internals/looks.md); its picker is the GET detail page below.
    Route::post('/member/config/look', [MemberConfigController::class, 'updateLook'])->name('member.config.look');
    Route::post('/member/config/password', [MemberConfigController::class, 'updatePassword'])->name('member.config.password');
    Route::post('/member/config/withdrawal', [MemberConfigController::class, 'withdraw'])->name('member.config.withdrawal');
    Route::post('/member/config/email', [MemberConfigController::class, 'updateEmail'])
        ->middleware('throttle:email-change')->name('member.config.email');

    // Modern-only detail pages with no Classic twin, so no surface default and no `.modern.` name.
    Route::get('/member/config/look', [MemberConfigController::class, 'editLook'])->name('member.config.look.edit');
    Route::get('/member/config/email', [MemberConfigController::class, 'editEmail'])->name('member.config.email.edit');
    Route::get('/member/config/password', [MemberConfigController::class, 'editPassword'])->name('member.config.password.edit');
    Route::get('/member/config/withdrawal', [MemberConfigController::class, 'editWithdrawal'])->name('member.config.withdrawal.edit');

    Route::get('/member/config/notifications', [NotificationSettingsController::class, 'edit'])->name('member.config.notifications.edit');
    Route::post('/member/config/notifications', [NotificationSettingsController::class, 'update'])->name('member.config.notifications');
    // The global push pause switch, its own POST like every other member-config section.
    Route::post('/member/config/notifications/push', [NotificationSettingsController::class, 'updatePush'])->name('member.config.notifications.push');

    Route::controller(AiAccountController::class)->group(function () {
        Route::get('/member/config/ai', 'index')->name('member.config.ai');
        // Creating and deleting share one per-member budget, like the two-factor management POSTs.
        Route::post('/member/config/ai', 'store')->middleware('throttle:ai-manage')->name('member.config.ai.store');

        // Route middleware rather than only Gate::authorize, so it outranks the password-carrying
        // FormRequests: a wrong password against a stranger's account must 404, not report a password error.
        Route::middleware('can:manageAiAccount,member')->group(function () {
            Route::get('/member/config/ai/{member}', 'show')->whereNumber('member')->name('member.config.ai.show');
            // No re-auth: a profile edit, not a credential change.
            Route::post('/member/config/ai/{member}', 'update')
                ->whereNumber('member')->middleware('throttle:ai-manage')->name('member.config.ai.update');
            Route::post('/member/config/ai/{member}/avatar', 'updateAvatar')
                ->whereNumber('member')->middleware('throttle:ai-manage')->name('member.config.ai.avatar');
            Route::post('/member/config/ai/{member}/avatar/delete', 'destroyAvatar')
                ->whereNumber('member')->middleware('throttle:ai-manage')->name('member.config.ai.avatar.delete');
            Route::post('/member/config/ai/{member}/delete', 'destroy')
                ->whereNumber('member')->middleware('throttle:ai-manage')->name('member.config.ai.destroy');
            // The token pair spends the same budget and carries no `mcp` feature gate on purpose: the
            // unit is the endpoint's kill switch, and revoking a token has to keep working while it
            // is off.
            Route::post('/member/config/ai/{member}/tokens', 'storeToken')
                ->whereNumber('member')->middleware('throttle:ai-manage')->name('member.config.ai.tokens.store');
            Route::post('/member/config/ai/{member}/tokens/{token}/delete', 'destroyToken')
                ->whereNumber(['member', 'token'])->middleware('throttle:ai-manage')->name('member.config.ai.tokens.destroy');
            // The AI's membership is its own: it survives the owner leaving the same group and is given
            // up only from here.
            Route::middleware(EnsureFeatureEnabled::class.':group')->group(function () {
                Route::post('/member/config/ai/{member}/groups/{group}/join', 'joinGroup')
                    ->whereNumber(['member', 'group'])->middleware('throttle:group-join')->name('member.config.ai.groups.join');
                Route::post('/member/config/ai/{member}/groups/{group}/quit', 'quitGroup')
                    ->whereNumber(['member', 'group'])->name('member.config.ai.groups.quit');
                Route::post('/member/config/ai/{member}/groups/{group}/cancel', 'cancelGroupRequest')
                    ->whereNumber(['member', 'group'])->name('member.config.ai.groups.cancel');
            });
        });
    });

    Route::controller(MemberMfaController::class)->group(function () {
        // The management POSTs share one per-member budget (FortifyServiceProvider's mfa-manage
        // limiter); the GET render is left out so a refresh never spends it.
        Route::middleware('throttle:mfa-manage')->group(function () {
            Route::post('/member/config/mfa/enable', 'enable')->name('member.config.mfa.enable');
            Route::post('/member/config/mfa/confirm', 'confirm')->name('member.config.mfa.confirm');
            Route::post('/member/config/mfa/disable', 'disable')->name('member.config.mfa.disable');
            Route::post('/member/config/mfa/recovery-codes', 'regenerate')->name('member.config.mfa.recovery');
        });
        // Modern-only detail page, like the email/password/withdrawal ones above.
        Route::get('/member/config/mfa', 'edit')->name('member.config.mfa.edit');
    });

    Route::prefix('member')->controller(MemberAvatarController::class)->group(function () {
        Route::get('/avatar', 'edit')->name('member.avatar.edit');
        Route::post('/avatar', 'update')->name('member.avatar.update');
        Route::delete('/avatar', 'destroy')->name('member.avatar.destroy');
        Route::post('/avatar/color', 'updateColor')->name('member.avatar.color');
    });

    // OpenPNE 3 served the avatar editor at /member/image/config; preserve the URL.
    Route::get('/member/image/config', fn () => redirect()->route('member.avatar.edit'))
        ->name('member.image.config_compat');

    // OpenPNE 3 own-profile alias (routing.yml member_profile_mine): /member/profile is the
    // viewer's own profile — login-required (it needs the viewer), redirects to /member/{id}.
    Route::get('/member/profile', fn (Request $request) => redirect()->route('member.profile.show', ['member' => $request->user()->getKey()]))
        ->name('member.profile.mine_compat');

    // OpenPNE 3 member/search.
    Route::get('/member/search', [MemberSearchController::class, 'search'])->name('member.search');

    // OpenPNE 3 member/editProfile.
    Route::get('/member/edit/profile', [ProfileController::class, 'edit'])->name('member.profile.edit');
    Route::post('/member/edit/profile', [ProfileController::class, 'update'])->name('member.profile.update');

    // OpenPNE 3 community.
    Route::prefix('groups')->middleware(EnsureFeatureEnabled::class.':group')->controller(GroupController::class)->group(function () {
        Route::get('/', 'search')->name('group.search');
        Route::get('/mine', 'listMine')->name('group.list_mine');
        // Single endpoint for new+edit and create+update (?id= switches), as in OpenPNE 3.
        Route::get('/edit', 'edit')->name('group.edit');
        Route::post('/edit', 'save')->name('group.save');
        // join / quit / delete: GET confirm, POST submit.
        Route::get('/{group}/join', 'showJoin')->whereNumber('group')->name('group.join.show');
        Route::post('/{group}/join', 'join')->whereNumber('group')->middleware('throttle:group-join')->name('group.join');
        Route::get('/{group}/quit', 'showQuit')->whereNumber('group')->name('group.quit.show');
        Route::post('/{group}/quit', 'quit')->whereNumber('group')->name('group.quit');
        Route::get('/{group}/delete', 'showDelete')->whereNumber('group')->name('group.delete.show');
        Route::post('/{group}/delete', 'delete')->whereNumber('group')->name('group.delete');
        // Member roster + pending-member approval.
        Route::get('/{group}/members', 'members')->whereNumber('group')->name('group.members');
        Route::get('/{group}/members/pending', 'pendingMembers')->whereNumber('group')->name('group.members.pending');
        Route::post('/{group}/members/approve', 'approve')->whereNumber('group')->middleware('throttle:group-join')->name('group.members.approve');
        Route::post('/{group}/members/decline', 'decline')->whereNumber('group')->middleware('throttle:group-join')->name('group.members.decline');

        // The operation endpoints name their target in member_id, as OpenPNE 3 did through its
        // module/action fallback.
        Route::controller(GroupMemberManageController::class)->group(function () {
            Route::get('/{group}/members/manage', 'manage')->whereNumber('group')->name('group.members.manage');
            Route::get('/{group}/members/appoint', 'showAppoint')->whereNumber('group')->name('group.members.appoint.show');
            Route::post('/{group}/members/appoint', 'appoint')->whereNumber('group')->name('group.members.appoint');
            Route::get('/{group}/members/demote', 'showDemote')->whereNumber('group')->name('group.members.demote.show');
            Route::post('/{group}/members/demote', 'demote')->whereNumber('group')->name('group.members.demote');
            Route::get('/{group}/members/drop', 'showDrop')->whereNumber('group')->name('group.members.drop.show');
            Route::post('/{group}/members/drop', 'drop')->whereNumber('group')->name('group.members.drop');
            // Admin transfer: request (GET confirm + POST) from the roster, then the nominee
            // accepts/rejects from a banner on the group home (POST only — no confirm page).
            Route::get('/{group}/members/transfer', 'showTransfer')->whereNumber('group')->name('group.members.transfer.show');
            Route::post('/{group}/members/transfer', 'transfer')->whereNumber('group')->name('group.members.transfer');
            Route::post('/{group}/members/transfer/accept', 'acceptTransfer')->whereNumber('group')->name('group.members.transfer.accept');
            Route::post('/{group}/members/transfer/reject', 'rejectTransfer')->whereNumber('group')->name('group.members.transfer.reject');
        });

        Route::get('/{group}', 'show')->whereNumber('group')->name('group.show');
    });

    // OpenPNE 3 /community/* redirects, GET only: a POST submit is not a bookmarkable URL.
    Route::prefix('community')->middleware(EnsureFeatureEnabled::class.':group')->group(function () {
        $byQueryId = fn (string $route) => function (Request $request) use ($route) {
            abort_unless(ctype_digit((string) $request->query('id')), 404);

            return redirect()->route($route, ['group' => $request->integer('id')]
                + array_diff_key($request->query(), ['id' => null]));
        };

        // The OpenPNE 3 search query shape (community[name], community[community_category_id],
        // search_query, page) rides along — GroupController still accepts it, so a bookmarked
        // search must not degrade into the unfiltered list.
        Route::get('/search', fn (Request $request) => redirect()->route('group.search', $request->query()))
            ->name('group.search.compat');
        // ?id= is the member whose list is shown here, not a group — it rides along unchanged.
        Route::get('/joinList', fn (Request $request) => redirect()->route('group.list_mine', $request->query()))
            ->name('group.list_mine.compat');
        // Both surfaces' create form; ?id= switches it to edit, and group.edit keeps that shape.
        Route::get('/edit', fn (Request $request) => redirect()->route('group.edit', $request->query()))
            ->name('group.edit.compat');
        Route::get('/join', $byQueryId('group.join.show'))->name('group.join.compat');
        Route::get('/quit', $byQueryId('group.quit.show'))->name('group.quit.compat');
        Route::get('/member/list', $byQueryId('group.members'))->name('group.members.compat');
        Route::get('/member/pending', $byQueryId('group.members.pending'))->name('group.members.pending.compat');
        Route::get('/member/appointSubAdmin', $byQueryId('group.members.appoint.show'))->name('group.members.appoint.compat');
        Route::get('/member/demoteSubAdmin', $byQueryId('group.members.demote.show'))->name('group.members.demote.compat');
        Route::get('/member/drop', $byQueryId('group.members.drop.show'))->name('group.members.drop.compat');
        Route::get('/member/transferAdmin', $byQueryId('group.members.transfer.show'))->name('group.members.transfer.compat');
        // Path-id redirects keep the query too (the member-manage target paginates), with the
        // path parameter winning over a stray ?group=.
        Route::get('/member/manage/{group}', fn (Request $request, int $group) => redirect()->route('group.members.manage', ['group' => $group] + $request->query()))
            ->whereNumber('group')->name('group.members.manage.compat');
        Route::get('/delete/{group}', fn (Request $request, int $group) => redirect()->route('group.delete.show', ['group' => $group] + $request->query()))
            ->whereNumber('group')->name('group.delete.compat');
        Route::get('/recent', fn (Request $request) => redirect()->route('group.recent', $request->query()))
            ->name('group.recent.compat');
        Route::get('/{group}', fn (Request $request, int $group) => redirect()->route('group.show', ['group' => $group] + $request->query()))
            ->whereNumber('group')->name('group.show.compat');
    });

    Route::middleware(EnsureFeatureEnabled::class.':groupTopic')->controller(GroupTopicController::class)->group(function () {
        Route::get('/groups/{group}/topics', 'index')->whereNumber('group')->name('group.topics.index');
        Route::get('/groups/{group}/topics/new', 'new')->whereNumber('group')->name('group.topics.new');
        Route::post('/groups/{group}/topics', 'store')->whereNumber('group')->middleware('throttle:posting')->name('group.topics.store');
        // edit / delete: GET confirm, POST submit on the same URL (as the group core's).
        Route::get('/topics/{topic}/edit', 'edit')->whereNumber('topic')->name('group.topics.edit');
        Route::post('/topics/{topic}/edit', 'update')->whereNumber('topic')->middleware('throttle:posting')->name('group.topics.update');
        Route::get('/topics/{topic}/delete', 'showDelete')->whereNumber('topic')->name('group.topics.delete.show');
        Route::post('/topics/{topic}/delete', 'delete')->whereNumber('topic')->name('group.topics.delete');
        Route::get('/topics/{topic}', 'show')->whereNumber('topic')->name('group.topics.show');
    });

    // OpenPNE 3 communityTopicComment.
    Route::controller(GroupTopicCommentController::class)->middleware(EnsureFeatureEnabled::class.':groupTopic')->group(function () {
        Route::post('/topics/{topic}/comments', 'store')->whereNumber('topic')->middleware('throttle:posting')->name('group.topics.comment.store');
        Route::get('/topics/comments/{comment}/delete', 'showDelete')->whereNumber('comment')->name('group.topics.comment.delete.show');
        Route::post('/topics/comments/{comment}/delete', 'delete')->whereNumber('comment')->name('group.topics.comment.delete');
    });

    // OpenPNE 3 /communityTopic/* redirects, GET only; the query rides along so a ?page=N bookmark
    // does not reset to page 1.
    Route::prefix('communityTopic')->middleware(EnsureFeatureEnabled::class.':groupTopic')->group(function () {
        Route::get('/listCommunity/{group}', fn (Request $request, int $group) => redirect()->route('group.topics.index', ['group' => $group] + $request->query()))
            ->whereNumber('group')->name('group.topics.index.compat');
        Route::get('/new/{group}', fn (Request $request, int $group) => redirect()->route('group.topics.new', ['group' => $group] + $request->query()))
            ->whereNumber('group')->name('group.topics.new.compat');
        Route::get('/edit/{topic}', fn (Request $request, int $topic) => redirect()->route('group.topics.edit', ['topic' => $topic] + $request->query()))
            ->whereNumber('topic')->name('group.topics.edit.compat');
        Route::get('/deleteConfirm/{topic}', fn (Request $request, int $topic) => redirect()->route('group.topics.delete.show', ['topic' => $topic] + $request->query()))
            ->whereNumber('topic')->name('group.topics.delete.show.compat');
        Route::get('/comment/deleteConfirm/{comment}', fn (Request $request, int $comment) => redirect()->route('group.topics.comment.delete.show', ['comment' => $comment] + $request->query()))
            ->whereNumber('comment')->name('group.topics.comment.delete.show.compat');
        Route::get('/{topic}', fn (Request $request, int $topic) => redirect()->route('group.topics.show', ['topic' => $topic] + $request->query()))
            ->whereNumber('topic')->name('group.topics.show.compat');
    });

    // Modern-only, with no OpenPNE 3 equivalent and so no compat block.
    Route::middleware(EnsureFeatureEnabled::class.':groupTalk')->controller(GroupTalkController::class)->group(function () {
        Route::get('/groups/{group}/talk', 'show')->whereNumber('group')->name('group.talk.show');
        // One page either side of a cursor the client was handed: `after` for the poll, `before`
        // for "load older".
        Route::get('/groups/{group}/talk/messages', 'messages')->whereNumber('group')->name('group.talk.messages');
        Route::get('/groups/{group}/talk/mention-candidates', 'mentionCandidates')->whereNumber('group')
            ->middleware('throttle:mention-search')->name('group.talk.mention_candidates');
        Route::post('/groups/{group}/talk', 'store')->whereNumber('group')
            ->middleware('throttle:posting')->name('group.talk.store');
        // Neither is throttled as posting: one is a reading side effect and the other a preference.
        Route::post('/groups/{group}/talk/read', 'read')->whereNumber('group')->name('group.talk.read');
        Route::post('/groups/{group}/talk/mute', 'mute')->whereNumber('group')->name('group.talk.mute');
        // POST delete on its own URL, as the boards do; the path carries the group as well as the
        // message so the two can be checked against each other.
        Route::post('/groups/{group}/talk/messages/{message}/delete', 'delete')
            ->whereNumber(['group', 'message'])->name('group.talk.delete');
    });

    // Add and remove are two URLs rather than one toggle, so a retry or a double tap settles at the
    // state asked for.
    Route::middleware(EnsureFeatureEnabled::class.':groupTalk')->controller(GroupTalkReactionController::class)->group(function () {
        Route::post('/groups/{group}/talk/messages/{message}/reactions', 'store')
            ->whereNumber(['group', 'message'])->middleware('throttle:reaction')->name('group.talk.reactions.store');
        Route::post('/groups/{group}/talk/messages/{message}/reactions/delete', 'delete')
            ->whereNumber(['group', 'message'])->middleware('throttle:reaction')->name('group.talk.reactions.delete');
        Route::get('/groups/{group}/talk/messages/{message}/reactions', 'index')
            ->whereNumber(['group', 'message'])->name('group.talk.reactions.index');
    });

    Route::middleware(EnsureFeatureEnabled::class.':groupEvent')->controller(GroupEventController::class)->group(function () {
        Route::get('/groups/{group}/events', 'index')->whereNumber('group')->name('group.events.index');
        Route::get('/groups/{group}/events/new', 'new')->whereNumber('group')->name('group.events.new');
        Route::post('/groups/{group}/events', 'store')->whereNumber('group')->middleware('throttle:posting')->name('group.events.store');
        // edit / delete: GET confirm, POST submit on the same URL (as the group core's).
        Route::get('/events/{event}/edit', 'edit')->whereNumber('event')->name('group.events.edit');
        Route::post('/events/{event}/edit', 'update')->whereNumber('event')->middleware('throttle:posting')->name('group.events.update');
        Route::get('/events/{event}/delete', 'showDelete')->whereNumber('event')->name('group.events.delete.show');
        Route::post('/events/{event}/delete', 'delete')->whereNumber('event')->name('group.events.delete');
        Route::get('/events/{event}/members', 'memberList')->whereNumber('event')->name('group.events.member_list');
        Route::get('/events/{event}', 'show')->whereNumber('event')->name('group.events.show');
    });

    // OpenPNE 3 communityEventComment; the post carries the merged RSVP form.
    Route::controller(GroupEventCommentController::class)->middleware(EnsureFeatureEnabled::class.':groupEvent')->group(function () {
        Route::post('/events/{event}/comments', 'store')->whereNumber('event')->middleware('throttle:posting')->name('group.events.comment.store');
        Route::get('/events/comments/{comment}/delete', 'showDelete')->whereNumber('comment')->name('group.events.comment.delete.show');
        Route::post('/events/comments/{comment}/delete', 'delete')->whereNumber('comment')->name('group.events.comment.delete');
    });

    // OpenPNE 3 /communityEvent/* redirects, GET only; the query rides along so a ?page=N bookmark
    // does not reset to page 1.
    Route::prefix('communityEvent')->middleware(EnsureFeatureEnabled::class.':groupEvent')->group(function () {
        Route::get('/listCommunity/{group}', fn (Request $request, int $group) => redirect()->route('group.events.index', ['group' => $group] + $request->query()))
            ->whereNumber('group')->name('group.events.index.compat');
        Route::get('/new/{group}', fn (Request $request, int $group) => redirect()->route('group.events.new', ['group' => $group] + $request->query()))
            ->whereNumber('group')->name('group.events.new.compat');
        Route::get('/edit/{event}', fn (Request $request, int $event) => redirect()->route('group.events.edit', ['event' => $event] + $request->query()))
            ->whereNumber('event')->name('group.events.edit.compat');
        Route::get('/deleteConfirm/{event}', fn (Request $request, int $event) => redirect()->route('group.events.delete.show', ['event' => $event] + $request->query()))
            ->whereNumber('event')->name('group.events.delete.show.compat');
        Route::get('/comment/deleteConfirm/{comment}', fn (Request $request, int $comment) => redirect()->route('group.events.comment.delete.show', ['comment' => $comment] + $request->query()))
            ->whereNumber('comment')->name('group.events.comment.delete.show.compat');
        Route::get('/{event}/memberList', fn (Request $request, int $event) => redirect()->route('group.events.member_list', ['event' => $event] + $request->query()))
            ->whereNumber('event')->name('group.events.member_list.compat');
        Route::get('/{event}', fn (Request $request, int $event) => redirect()->route('group.events.show', ['event' => $event] + $request->query()))
            ->whereNumber('event')->name('group.events.show.compat');
    });

    // OpenPNE 3 message.
    Route::prefix('message')->middleware(EnsureFeatureEnabled::class.':directMessage')->controller(DirectMessageController::class)->group(function () {
        Route::get('/', 'index')->name('message.index');
        Route::get('/index', 'index')->name('message.index_compat');
        Route::get('/receiveList', 'receive')->name('message.receive');
        Route::get('/sendList', 'send')->name('message.send');
        Route::get('/draftList', 'draft')->name('message.draft');
        Route::get('/dustList', 'trash')->name('message.trash');
        Route::get('/sendToFriend', 'compose')->name('message.compose');
        Route::post('/sendToFriend', 'store')->middleware('throttle:direct-message-send')->name('message.compose.store');
        Route::get('/reply/{message}', 'reply')->whereNumber('message')->name('message.reply');
        Route::get('/edit/{message}', 'edit')->whereNumber('message')->name('message.draft.edit');
        // The final send passes through this POST; if compose ever gains autosave the per-member limit must be revisited.
        Route::post('/edit/{message}', 'update')->whereNumber('message')->middleware('throttle:direct-message-send')->name('message.draft.update');
        Route::get('/read/{message}', 'showReceived')->whereNumber('message')->name('message.receive.show');
        Route::get('/check/{message}', 'showSent')->whereNumber('message')->name('message.send.show');
        Route::get('/checkDelete/{message}', 'showTrashed')->whereNumber('message')->name('message.trash.show');
        Route::post('/deleteReceiveMessage/{message}', 'trashReceived')->whereNumber('message')->name('message.receive.trash');
        Route::post('/deleteSendMessage/{message}', 'trashSent')->whereNumber('message')->name('message.send.trash');
        Route::post('/restore/{message}', 'restore')->whereNumber('message')->name('message.trash.restore');
        Route::get('/deleteConfirm/{message}', 'purgeConfirm')->whereNumber('message')->name('message.trash.purge.confirm');
        Route::post('/deleteComplete/{message}', 'purge')->whereNumber('message')->name('message.trash.purge');
        Route::post('/bulk', 'bulk')->name('message.bulk');
    });

    // Beside /message/* rather than inside it: the mailbox URLs are OpenPNE 3's and stay exactly as
    // they are.
    Route::prefix('messages')->middleware(EnsureFeatureEnabled::class.':directMessage')->controller(ConversationController::class)->group(function () {
        Route::get('/', 'index')->name('message.chat.index');
        Route::get('/new', 'new')->name('message.chat.new');
        Route::get('/recipients', 'recipients')->middleware('throttle:mention-search')->name('message.chat.recipients');
        // Everyone whose account is gone shares one conversation: a withdrawn member leaves no id to
        // key one by.
        Route::get('/withdrawn', 'showWithdrawn')->name('message.chat.withdrawn');
        Route::get('/withdrawn/messages', 'withdrawnMessages')->name('message.chat.withdrawn.messages');
        Route::post('/withdrawn/read', 'readWithdrawn')->name('message.chat.withdrawn.read');
        Route::post('/withdrawn/delete', 'deleteWithdrawn')->name('message.chat.withdrawn.delete');
        Route::get('/{member}', 'show')->whereNumber('member')->name('message.chat.show');
        Route::get('/{member}/messages', 'messages')->whereNumber('member')->name('message.chat.messages');
        // The withdrawn bucket has no counterpart to deliver to, so it has no store route.
        Route::post('/{member}', 'store')->whereNumber('member')->middleware('throttle:direct-message-send')->name('message.chat.store');
        // The reader's own state, so it is not throttled as posting: reading is what opening the
        // screen does.
        Route::post('/{member}/read', 'read')->whereNumber('member')->name('message.chat.read');
        // Per-side and never a retraction, so it is the viewer's own state too rather than a write
        // anyone else sees (DeleteConversation).
        Route::post('/{member}/delete', 'delete')->whereNumber('member')->name('message.chat.delete');
    });
});

// Retired /m/ GET shapes that do not map onto their canonical URL by dropping the prefix, ahead of
// the catch-all; a retired POST shape was never persisted anywhere and 404s.
$mCompat = fn (string $target) => redirect()->to(
    $target.(($qs = request()->getQueryString()) === null ? '' : (str_contains($target, '?') ? '&' : '?').$qs), 308
);
Route::get('/m/community/joined', fn () => $mCompat('/groups/mine'));
Route::get('/m/community/topic/{topic}', fn (int $topic) => $mCompat("/topics/{$topic}"))->whereNumber('topic');
Route::get('/m/community/topic/{topic}/edit', fn (int $topic) => $mCompat("/topics/{$topic}/edit"))->whereNumber('topic');
Route::get('/m/community/event/{event}', fn (int $event) => $mCompat("/events/{$event}"))->whereNumber('event');
Route::get('/m/community/event/{event}/edit', fn (int $event) => $mCompat("/events/{$event}/edit"))->whereNumber('event');
Route::get('/m/community/event/{event}/members', fn (int $event) => $mCompat("/events/{$event}/members"))->whereNumber('event');
Route::get('/m/community/{group}/members', fn (int $group) => $mCompat("/groups/{$group}/members"))->whereNumber('group');
Route::get('/m/community/{group}/pending', fn (int $group) => $mCompat("/groups/{$group}/members/pending"))->whereNumber('group');
Route::get('/m/community/{group}/topic', fn (int $group) => $mCompat("/groups/{$group}/topics"))->whereNumber('group');
Route::get('/m/community/{group}/topic/new', fn (int $group) => $mCompat("/groups/{$group}/topics/new"))->whereNumber('group');
Route::get('/m/community/{group}/event', fn (int $group) => $mCompat("/groups/{$group}/events"))->whereNumber('group');
Route::get('/m/community/{group}/event/new', fn (int $group) => $mCompat("/groups/{$group}/events/new"))->whereNumber('group');

// 308 keeps the method for stale in-flight forms; the canonical target resolves the surface itself.
Route::any('/m/{path?}', function (Request $request, string $path = '') {
    $query = $request->getQueryString();

    return redirect()->to('/'.$path.($query === null ? '' : '?'.$query), 308);
})->where('path', '.*')->name('compat.m_prefix');

// A router-level 404 has no session, locale or Classic shell; routing it here makes it an ordinary
// request.
Route::fallback(fn (Request $request) => ClassicErrorPage::respond($request, new NotFoundHttpException));
