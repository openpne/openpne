<?php

use App\Captcha\Captcha;
use App\Features\Auth\RegistrationController;
use App\Features\Block\BlockController;
use App\Features\Community\CommunityController;
use App\Features\CommunityEvent\CommunityEventCommentController;
use App\Features\CommunityEvent\CommunityEventController;
use App\Features\CommunityTopic\CommunityTopicCommentController;
use App\Features\CommunityTopic\CommunityTopicController;
use App\Features\Diary\DiaryCommentController;
use App\Features\Diary\DiaryController;
use App\Features\Friend\FriendController;
use App\Features\Home\HomeController;
use App\Features\Member\EmailChangeLinkController;
use App\Features\Member\InviteController;
use App\Features\Member\MemberAvatarController;
use App\Features\Member\MemberConfigController;
use App\Features\Member\MemberMfaController;
use App\Features\Member\MemberSearchController;
use App\Features\Message\MessageController;
use App\Features\Notifications\NotificationFeedController;
use App\Features\Notifications\NotificationSettingsController;
use App\Features\Profile\ProfileController;
use App\Features\Timeline\TimelineController;
use App\Http\Controllers\Admin\AdminFileController;
use App\Http\Controllers\BannerImageController;
use App\Http\Controllers\CustomizingCssController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\PublicFileController;
use App\Http\Middleware\AsBackgroundFetch;
use App\Http\Middleware\EnsureMemberInviteAllowed;
use App\Http\Middleware\EnsureOpenRegistration;
use App\Http\Middleware\NoReferrer;
use App\Http\Middleware\SetLocale;
use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\NewPasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;

// Canonical OpenPNE 3 homepage (member/home). Resolves by surface: a Modern surface redirects to
// the Inertia dashboard, Classic renders the OpenPNE 3 gadget home.
Route::get('/', [HomeController::class, 'index'])->name('home');

// OpenPNE 3 member_index alias (/member) for the same member/home portal.
Route::get('/member', fn () => redirect('/'))->name('member.index_compat');

Route::post('/locale', function (Request $request) {
    $locale = (string) $request->input('locale');
    if (in_array($locale, SetLocale::SUPPORTED_LOCALES, strict: true)) {
        $request->session()->put('locale', $locale);
        // Persist for an authenticated member so the choice is durable across sessions and
        // outranks the session toggle on the next request (SetLocale step 1). Keeps the column
        // and session in sync so they never disagree for a logged-in member.
        $member = $request->user('member');
        if ($member instanceof Member) {
            $member->forceFill(['locale' => $locale])->save();
        }
    }

    // For Inertia requests we force a hard navigation. The React provider reads
    // `locale` only from `initialPage.props` at app boot, so following the 302
    // via XHR would refresh shared props but leave the provider on the old
    // locale. `Inertia::location()` makes the client do `window.location = url`
    // which remounts the provider and picks up the new locale.
    $target = url()->previous();
    if ($request->header('X-Inertia')) {
        return Inertia::location($target);
    }

    return redirect($target);
})->name('locale.switch');

// Session-only locale toggle for the Filament admin panel (and its login screen). Unlike
// `locale.switch` this NEVER writes members.locale: a co-logged-in member switching the panel
// language must not have their durable preference changed (OpenPNE 3 pc_backend changeLanguage
// is per-admin session culture, isolated from member config). The admin switcher fetches this
// and reloads, so a 204 is enough. Lives under /admin so it runs on the admin session store
// (UseAdminSessionStore): the panel-embedded CSRF token must validate against — and the
// locale write must land in — the store the panel's SetLocale:session reads.
Route::post('/admin/locale/session', function (Request $request) {
    $locale = (string) $request->input('locale');
    if (in_array($locale, SetLocale::SUPPORTED_LOCALES, strict: true)) {
        $request->session()->put('locale', $locale);
    }

    return response()->noContent();
})->name('locale.switch.session');

// Member profile page — public so a web-public profile is reachable by a guest. A guest on a
// non-web-public profile is redirected to login by ProfileController; per-value visibility, the
// is_public_web gate, and owner→viewer block are enforced in ShowProfile. whereNumber keeps the
// literal /member/* routes (avatar, config, profile) from matching the {member} wildcard.
Route::get('/member/{member}', [ProfileController::class, 'show'])
    ->whereNumber('member')->name('member.profile.show');
// OpenPNE 3 member_profile_raw alias (/member/profile/id/:id/*) → canonical /member/{id}.
// OpenPNE 3's trailing splat matched extra path segments; capture and ignore them so the
// whole legacy URL space redirects instead of 404ing past the id.
Route::get('/member/profile/id/{member}/{tail?}', fn (int $member) => redirect()->route('member.profile.show', ['member' => $member]))
    ->whereNumber('member')->where('tail', '.*')->name('member.profile.raw_compat');

// Fortify's own route registration is off (Fortify::ignoreRoutes() in FortifyServiceProvider):
// enabling the two-factor feature would otherwise also ship Fortify's /user/two-factor-*
// management endpoints, which bypass this app's management contract (inline current_password
// re-auth + session revocation on factor change — docs/internals/security.md). The routes this
// app does use are declared here instead, pinning the vendor names, methods and middleware
// (guarded by FortifyRoutesTest); the never-referenced password.confirm trio is not carried over.
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

// OpenPNE 3 served login at /member/login/*; login moved to Fortify's /login. Preserve the legacy
// URL with a redirect (guest-reachable, so outside the auth group). The {member} route above is
// whereNumber, so /member/login never matches it regardless of order.
Route::get('/member/login/{tail?}', fn () => redirect()->route('login'))
    ->where('tail', '.*')->name('member.login_compat');

// OpenPNE 3 withdrawal lived at GET/POST /leave; OpenPNE 4 serves it as the member-config withdrawal
// category. Preserve the bookmarkable GET URL with a redirect (the submit is member.config.withdrawal,
// not POST /leave). Guest-reachable: the config target bounces a logged-out visitor to /login.
Route::get('/leave', fn () => redirect()->route('member.config', ['category' => 'withdrawal']))
    ->name('member.leave_compat');

// OpenPNE 3 sites carrying the notification extension served its settings at
// member/configNotification (a global-fallback URL, no named route); OpenPNE 4 serves them as
// the member-config notification category.
Route::get('/member/configNotification', fn () => redirect()->route('member.config', ['category' => 'notification']))
    ->name('member.config_notification_compat');

// Email-change confirmation (OpenPNE 3 member/configComplete; OpenPNE 4-native URL). Token-gated and
// reachable whether or not the visitor is logged in (the member may open the link on another device),
// so it is neither guest- nor auth-restricted. GET renders a confirm page; the change happens on POST,
// so a mail scanner / link prefetch cannot consume the token and flip the login identifier. Per-IP
// throttled and length-pinned to the issued token shape.
Route::get('/member/config/email/confirm/{token}', [EmailChangeLinkController::class, 'confirmEmailForm'])
    ->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1')->name('member.config.email.confirm');
Route::post('/member/config/email/confirm/{token}', [EmailChangeLinkController::class, 'confirmEmail'])
    ->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1')->name('member.config.email.confirm.submit');

// Email-change cancellation, carried by the old-address security notice. Same public, token-gated,
// GET-renders-POST-acts shape as confirmation above (a second token, distinct from the confirm one),
// so the old-address holder can void a change they did not initiate without signing in. Cancelling
// only deletes the pending row — it never itself alters the login identifier — so no member match is
// required and a prefetch of the GET is harmless.
Route::get('/member/config/email/cancel/{token}', [EmailChangeLinkController::class, 'cancelEmailForm'])
    ->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1')->name('member.config.email.cancel');
Route::post('/member/config/email/cancel/{token}', [EmailChangeLinkController::class, 'cancelEmail'])
    ->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1')->name('member.config.email.cancel.submit');

// OpenPNE 3 password recovery lived under the opAuthMailAddress plugin. Fortify owns the canonical
// /forgot-password and /reset-password/{token}; the OpenPNE 3 token scheme (id + token) cannot be
// honored by Fortify (email + path token), so both legacy entry points restart at the request form.
Route::get('/opAuthMailAddress/passwordRecovery', fn () => redirect()->route('password.request'))
    ->name('auth.password_recovery_compat');
Route::get('/opAuthMailAddress/passwordRecoveryComplete', fn () => redirect()->route('password.request'))
    ->name('auth.password_recovery_complete_compat');

// Multi-stage registration (OpenPNE 3 email-confirmation flow), replacing Fortify's single-stage
// /register. Guest-only. The email-entry half (request the token, neutral confirmation) is the open
// self-service entry, 404'd outside 'open' mode (OpenPNE 3 parity). The completion half is gated by
// the token itself, not the mode entry — an invited member must finish in invite/admin_only mode — so
// it sits outside EnsureOpenRegistration and the controller re-checks the mode against the token's
// origin (RegistrationController::resolveForCompletion). The literal /register/sent precedes
// /register/{token}, and the token is length-pinned to the issued shape, so the two never collide.
Route::middleware(['guest', NoReferrer::class, EnsureOpenRegistration::class])->controller(RegistrationController::class)->group(function () {
    Route::get('/register', 'requestForm')->name('register');
    Route::post('/register', 'request')->middleware('throttle:register-email')->name('register.request');
    Route::get('/register/sent', 'sent')->name('register.sent');
});
Route::middleware(['guest', NoReferrer::class])->controller(RegistrationController::class)->group(function () {
    Route::get('/register/{token}', 'form')->where('token', '[A-Za-z0-9]{40}')
        ->middleware('throttle:register-complete')->name('register.form');
    Route::post('/register/{token}', 'register')->where('token', '[A-Za-z0-9]{40}')
        ->middleware('throttle:register-complete')->name('register.complete');
});

// Fresh ALTCHA challenge for the widget to solve. Throttled per IP; returns {} when CAPTCHA is off.
// AsBackgroundFetch keeps this JSON endpoint out of the session's previous-URL history so a later
// redirect()->back() never lands on it.
Route::get('/altcha/challenge', fn (Captcha $captcha) => response()->json($captcha->challenge()))
    ->middleware(['throttle:60,1', AsBackgroundFetch::class])->name('altcha.challenge');

// Web app manifest, dynamic so `name` mirrors the admin-configured SNS name. Declaring standalone
// display with a site-wide scope keeps home-screen launches free of browser chrome: without it iOS
// overlays a title bar on every in-app navigation, covering the top of the page. Colors match the
// Modern shell's theme-color meta.
Route::get('/manifest.webmanifest', fn () => response()->json([
    'name' => sns_name(),
    'short_name' => sns_name(),
    'start_url' => '/',
    'scope' => '/',
    'display' => 'standalone',
    'background_color' => '#ffffff',
    'theme_color' => '#2563eb',
    'icons' => [
        ['src' => asset('icon-192x192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => asset('icon-512x512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
    ],
], options: JSON_UNESCAPED_SLASHES)->header('Content-Type', 'application/manifest+json'))->name('webmanifest');

// Admin custom CSS, served as a text/css document the Classic shell <link>s (OpenPNE 3 parity:
// /cache/css/customizing.css). Public — it styles guest pages too — and dynamic from the DB, not a
// written cache file. See App\Http\Controllers\CustomizingCssController.
Route::get('/cache/css/customizing.css', [CustomizingCssController::class, 'show'])->name('design.customizing_css');

// Banner image bytes, public — OpenPNE 3 banners show to guests. Only banner-owned files are served
// here (BannerImageController 404s anything else); the rest of the file store stays behind the authed
// FileController. Bound by the opaque `name` token.
Route::get('/banner/image/{file:name}', [BannerImageController::class, 'show'])->name('banner.image');

// Admin-uploaded public asset bytes (explicit_visibility='public'), e.g. an image embedded in custom
// HTML/CSS. Public — no login — but PublicFileController 404s any file not explicitly marked public,
// keeping the rest of the store behind the authed FileController. Bound by the opaque `name` token.
Route::get('/file/public/{file:name}', [PublicFileController::class, 'show'])->name('file.public');

// Admin file monitor byte delivery (thumbnail + download). Gated by the `admin` guard inside the
// controller (404 for non-admins) and intentionally bypassing FilePolicy: an administrator may
// inspect any uploaded file. Bound by the opaque `name` token.
Route::get('/admin/file/{file:name}/raw', [AdminFileController::class, 'show'])->name('admin.file.raw');

// Member invitation (OpenPNE 3 member/invite): a logged-in member invites an address, which issues a
// registration token and mails the link. Gated to modes that allow member invites (open/invite);
// admin_only/closed 404 it. The send is throttled per member to bound invite mail.
Route::middleware(['auth', 'auth.session', EnsureMemberInviteAllowed::class])->controller(InviteController::class)->group(function () {
    Route::get('/invite', 'show')->name('member.invite');
    Route::post('/invite', 'submit')->middleware('throttle:member-invite')->name('member.invite.submit');
});

// auth.session (AuthenticateSession) drops a logged-in session on its next protected request once
// the member's password hash changes — a best-effort cross-driver fallback; the reset itself purges
// database-driver sessions outright (see ResetMemberPassword).
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::get('/dashboard', [HomeController::class, 'dashboard'])->name('dashboard');
    // The dashboard's community activity section, expanded. Modern-only (no OpenPNE 3 equivalent),
    // so it renders Inertia directly like /dashboard — not a surface twin.
    Route::get('/community/recent', [HomeController::class, 'communityActivity'])->name('community.recent');

    // The per-event notification feed (layer 3). Modern-only, like /community/recent — OpenPNE 3's
    // notification centre had no PC page to be compatible with.
    Route::prefix('notifications')->controller(NotificationFeedController::class)->group(function () {
        Route::get('/', 'index')->name('notifications.index');
        Route::post('/read-all', 'readAll')->name('notifications.readAll');
        Route::post('/{notification}/open', 'open')->whereUuid('notification')->name('notifications.open');
    });

    Route::prefix('friend')->controller(FriendController::class)->group(function () {
        Route::get('/list', 'list')->name('friend.list');
        Route::get('/manage', 'manage')->name('friend.manage');
        Route::get('/link', 'showLink')->name('friend.link.show');
        Route::post('/link', 'submitLink')->middleware('throttle:friend-request')->name('friend.link');
        Route::post('/accept', 'submitAccept')->middleware('throttle:friend-request')->name('friend.accept');
        Route::post('/reject', 'submitReject')->name('friend.reject');
        Route::get('/unlink/{member}', 'showUnlink')->name('friend.unlink.show');
        Route::post('/unlink/{member}', 'submitUnlink')->name('friend.unlink.submit');
    });

    Route::prefix('block')->controller(BlockController::class)->group(function () {
        Route::get('/list', 'list')->name('block.list');
        Route::get('/add', 'showAdd')->name('block.add.show');
        Route::post('/add', 'submitAdd')->name('block.add');
        Route::get('/remove/{member}', 'showRemove')->name('block.remove.show');
        Route::post('/remove/{member}', 'submitRemove')->name('block.remove.submit');
    });

    Route::prefix('diary')->controller(DiaryController::class)->group(function () {
        // Literal-prefix routes must precede the {diary} wildcard.
        Route::get('/search', 'search')->name('diary.search');
        Route::get('/list', 'list')->name('diary.list');
        Route::get('/listFriend', 'listFriend')->name('diary.list_friend');
        Route::get('/listMember/{member?}', 'listMember')->whereNumber('member')->name('diary.list_member');
        // Calendar archive: same listMember view narrowed to a month or day.
        Route::get('/listMember/{member}/{year}/{month}/{day?}', 'listMemberArchive')
            ->where(['member' => '[0-9]+', 'year' => '[12][0-9]{3}', 'month' => '0?[1-9]|1[0-2]', 'day' => '0?[1-9]|[12][0-9]|3[01]'])
            ->name('diary.list_member.archive');
        Route::get('/new', 'new')->name('diary.new');
        Route::post('/create', 'store')->middleware('throttle:posting')->name('diary.store');
        Route::get('/edit/{diary}', 'edit')->whereNumber('diary')->name('diary.edit');
        Route::post('/update/{diary}', 'update')->whereNumber('diary')->middleware('throttle:posting')->name('diary.update');
        Route::get('/deleteConfirm/{diary}', 'showDelete')->whereNumber('diary')->name('diary.delete.show');
        Route::post('/delete/{diary}', 'delete')->whereNumber('diary')->name('diary.delete');
        Route::get('/{diary}', 'show')->whereNumber('diary')->name('diary.show');
    });

    // OpenPNE 3 diaryComment module. create keys off the diary id; deleteConfirm/delete key
    // off the comment id (literal /diary/comment/* never collides with diary.show's numeric id).
    Route::controller(DiaryCommentController::class)->group(function () {
        Route::post('/diary/{diary}/comment/create', 'store')->whereNumber('diary')->middleware('throttle:posting')->name('diary.comment.store');
        Route::get('/diary/comment/deleteConfirm/{comment}', 'showDelete')->whereNumber('comment')->name('diary.comment.delete.show');
        Route::post('/diary/comment/delete/{comment}', 'delete')->whereNumber('comment')->name('diary.comment.delete');
    });

    Route::controller(DiaryCommentController::class)->group(function () {
        // No GET delete-confirm twin — Modern confirms delete inline (Radix AlertDialog).
    });

    // OpenPNE 3 opTimelinePlugin: the cross-member home feed, a member's timeline, posting, and a
    // single-post permalink. Literal-prefix routes precede the {timelinePost} wildcard.
    Route::controller(TimelineController::class)->group(function () {
        Route::get('/timeline', 'index')->name('timeline.index');
        Route::get('/member/{member}/timeline', 'member')->whereNumber('member')->name('timeline.member');
        Route::get('/timeline/new', 'new')->name('timeline.new');
        Route::post('/timeline/create', 'store')->middleware('throttle:posting')->name('timeline.store');
        Route::get('/timeline/deleteConfirm/{timelinePost}', 'showDelete')->whereNumber('timelinePost')->name('timeline.delete.show');
        Route::post('/timeline/delete/{timelinePost}', 'delete')->whereNumber('timelinePost')->name('timeline.delete');
        Route::post('/timeline/{timelinePost}/reply', 'storeReply')->whereNumber('timelinePost')->middleware('throttle:posting')->name('timeline.reply.store');
        Route::get('/timeline/{timelinePost}', 'show')->whereNumber('timelinePost')->name('timeline.show');
    });

    Route::controller(TimelineController::class)->group(function () {
        // No GET delete-confirm twin — Modern confirms delete inline (Radix AlertDialog).
    });

    // OpenPNE 3 linked the single-post permalink at /timeline/show/id/:id (reached via the global
    // /:module/:action fallback); preserve that URL by redirecting to the canonical timeline.show.
    Route::get('/timeline/show/id/{timelinePost}', fn (int $timelinePost) => redirect()->route('timeline.show', ['timelinePost' => $timelinePost]))
        ->whereNumber('timelinePost')->name('timeline.show.compat');

    // OpenPNE 3's SNS-wide timeline lived at /sns/timeline; preserve that URL by redirecting to the
    // canonical home feed at /timeline.
    Route::get('/sns/timeline', fn () => redirect()->route('timeline.index'))->name('timeline.index.compat');

    // OpenPNE 3 member/config — the member's own settings page. GET renders (Classic/Modern); each
    // section saves on its own POST so one change never rewrites another. The OpenPNE 3 access-block
    // category (/member/config?category=accessBlock) is redirected to the Block list inside show().
    Route::get('/member/config', [MemberConfigController::class, 'show'])->name('member.config');
    Route::post('/member/config/diary', [MemberConfigController::class, 'updateDiary'])->name('member.config.diary');
    Route::post('/member/config/age', [MemberConfigController::class, 'updateAge'])->name('member.config.age');
    Route::post('/member/config/surface', [MemberConfigController::class, 'updateSurface'])->name('member.config.surface');
    Route::post('/member/config/password', [MemberConfigController::class, 'updatePassword'])->name('member.config.password');
    Route::post('/member/config/withdrawal', [MemberConfigController::class, 'withdraw'])->name('member.config.withdrawal');
    Route::post('/member/config/email', [MemberConfigController::class, 'updateEmail'])
        ->middleware('throttle:email-change')->name('member.config.email');

    // Modern-only detail pages for the consequential account changes (email/password/withdrawal).
    // The settings page keeps a compact row per item; the forms live here, so a validation error
    // lands back on a short page where it is visible. Classic keeps its ?category= pages. Like
    // /community/recent these have no Classic twin, so no surface default / `.modern.` name.
    Route::get('/member/config/email', [MemberConfigController::class, 'editEmail'])->name('member.config.email.edit');
    Route::get('/member/config/password', [MemberConfigController::class, 'editPassword'])->name('member.config.password.edit');
    Route::get('/member/config/withdrawal', [MemberConfigController::class, 'editWithdrawal'])->name('member.config.withdrawal.edit');

    // Notification catalog opt-ins (see NotificationKind; Classic serves it
    // as ?category=notification). Modern edits on a detail page with per-toggle saves.
    Route::get('/member/config/notifications', [NotificationSettingsController::class, 'edit'])->name('member.config.notifications.edit');
    Route::post('/member/config/notifications', [NotificationSettingsController::class, 'update'])->name('member.config.notifications');

    // Member two-factor management (OpenPNE 4-native; Classic serves it as ?category=mfa).
    // Re-auth per flow (enable opens a window that covers confirm; disable/regenerate also demand a
    // second-factor proof); which actions re-demand it and which revoke other sessions live in
    // MemberMfaController.
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

    // OpenPNE 3 member/search (/member/search): search members by profile fields. Login-required
    // (members only); per-value visibility + block are enforced in SearchMembers.
    Route::get('/member/search', [MemberSearchController::class, 'search'])->name('member.search');

    // OpenPNE 3 member/editProfile (/member/edit/profile): the member edits their own profile
    // fields + per-value visibility. GET renders, POST saves — same URL as OpenPNE 3.
    Route::get('/member/edit/profile', [ProfileController::class, 'edit'])->name('member.profile.edit');
    Route::post('/member/edit/profile', [ProfileController::class, 'update'])->name('member.profile.update');

    // File byte delivery, bound by the opaque `name` token. FileController gates every
    // fetch through FilePolicy, so disk backends stream through the app too (never a
    // bare disk URL).
    Route::get('/file/{file:name}', [FileController::class, 'show'])->name('file.show');

    // OpenPNE 3-compatible thumbnail delivery. Same FilePolicy gate as the original;
    // the size must be whitelisted (ImageTransform), so arbitrary sizes 404.
    Route::get('/cache/img/{format}/{geometry}/{name}.{ext}', [ImageController::class, 'show'])
        ->where([
            'format' => 'jpg|png|gif|webp',
            'geometry' => 'w[0-9]*_h[0-9]*(_sq)?',
            // OpenPNE 3 file names allow [\w._-] (its route used ^[\w\d_\.\-]+$), e.g.
            // m_42_..._jpg or a literal test1.jpg; new names are Str::random alnum. `.`
            // is allowed too — the greedy match still binds the trailing `.{ext}`, and
            // the File-name lookup (plus Flysystem's traversal guard) gates what is served.
            'name' => '[A-Za-z0-9_.-]+',
            'ext' => 'jpg|png|gif|webp',
        ])
        ->name('image.show');

    // Community core (canonical / Classic). The literal routes precede the /{community} wildcard,
    // and {community} is digit-constrained, so a literal like /community/search can never be
    // captured as a community id.
    Route::prefix('community')->controller(CommunityController::class)->group(function () {
        Route::get('/search', 'search')->name('community.search');
        Route::get('/joinList', 'listMine')->name('community.list_mine');
        // Single endpoint for new+edit and create+update (?id= switches), as in OpenPNE 3.
        Route::get('/edit', 'edit')->name('community.edit');
        Route::post('/edit', 'save')->name('community.save');
        // join / quit: GET confirm, POST submit (community id via ?id=).
        Route::get('/join', 'showJoin')->name('community.join.show');
        Route::post('/join', 'join')->middleware('throttle:community-join')->name('community.join');
        Route::get('/quit', 'showQuit')->name('community.quit.show');
        Route::post('/quit', 'quit')->name('community.quit');
        // Member roster + pending-member approval.
        Route::get('/member/list', 'members')->name('community.members');
        Route::get('/member/pending', 'pendingMembers')->name('community.members.pending');
        Route::post('/member/approve', 'approve')->middleware('throttle:community-join')->name('community.members.approve');
        Route::post('/member/decline', 'decline')->middleware('throttle:community-join')->name('community.members.decline');
        // delete: GET confirm, POST submit (community id in the path, as in OpenPNE 3).
        Route::get('/delete/{community}', 'showDelete')->whereNumber('community')->name('community.delete.show');
        Route::post('/delete/{community}', 'delete')->whereNumber('community')->name('community.delete');
        Route::get('/{community}', 'show')->whereNumber('community')->name('community.show');
    });

    // Community topic board (Classic only; Modern is none). Literal-prefix routes precede the
    // /{topic} wildcard, and every id is digit-constrained, so a literal like /communityTopic/new
    // can never be captured as a topic id. listCommunity/new/create take a community id; the rest
    // take a topic id.
    Route::prefix('communityTopic')->controller(CommunityTopicController::class)->group(function () {
        Route::get('/listCommunity/{community}', 'index')->whereNumber('community')->name('communityTopic.index');
        Route::get('/new/{community}', 'new')->whereNumber('community')->name('communityTopic.new');
        Route::post('/create/{community}', 'store')->whereNumber('community')->middleware('throttle:posting')->name('communityTopic.store');
        Route::get('/edit/{topic}', 'edit')->whereNumber('topic')->name('communityTopic.edit');
        Route::post('/update/{topic}', 'update')->whereNumber('topic')->middleware('throttle:posting')->name('communityTopic.update');
        Route::get('/deleteConfirm/{topic}', 'showDelete')->whereNumber('topic')->name('communityTopic.delete.show');
        Route::post('/delete/{topic}', 'delete')->whereNumber('topic')->name('communityTopic.delete');
        Route::get('/{topic}', 'show')->whereNumber('topic')->name('communityTopic.show');
    });

    // communityTopicComment module. create keys off the topic id; deleteConfirm/delete key off the
    // comment id (literal /communityTopic/comment/* never collides with the numeric topic show).
    Route::controller(CommunityTopicCommentController::class)->group(function () {
        Route::post('/communityTopic/{topic}/comment/create', 'store')->whereNumber('topic')->middleware('throttle:posting')->name('communityTopic.comment.store');
        Route::get('/communityTopic/comment/deleteConfirm/{comment}', 'showDelete')->whereNumber('comment')->name('communityTopic.comment.delete.show');
        Route::post('/communityTopic/comment/delete/{comment}', 'delete')->whereNumber('comment')->name('communityTopic.comment.delete');
    });

    // Community events (Classic only; Modern is none). Same literal-before-wildcard rule as the topic
    // board: listCommunity/new/create take a community id, the rest an event id, and {event} is
    // digit-constrained, so /communityEvent/memberList-style literals can never be read as an event id.
    Route::prefix('communityEvent')->controller(CommunityEventController::class)->group(function () {
        Route::get('/listCommunity/{community}', 'index')->whereNumber('community')->name('communityEvent.index');
        Route::get('/new/{community}', 'new')->whereNumber('community')->name('communityEvent.new');
        Route::post('/create/{community}', 'store')->whereNumber('community')->middleware('throttle:posting')->name('communityEvent.store');
        Route::get('/edit/{event}', 'edit')->whereNumber('event')->name('communityEvent.edit');
        Route::post('/update/{event}', 'update')->whereNumber('event')->middleware('throttle:posting')->name('communityEvent.update');
        Route::get('/deleteConfirm/{event}', 'showDelete')->whereNumber('event')->name('communityEvent.delete.show');
        Route::post('/delete/{event}', 'delete')->whereNumber('event')->name('communityEvent.delete');
        Route::get('/{event}/memberList', 'memberList')->whereNumber('event')->name('communityEvent.member_list');
        Route::get('/{event}', 'show')->whereNumber('event')->name('communityEvent.show');
    });

    // communityEventComment module. create keys off the event id and carries the merged RSVP form;
    // deleteConfirm/delete key off the comment id (literal /communityEvent/comment/* never collides
    // with the numeric event show).
    Route::controller(CommunityEventCommentController::class)->group(function () {
        Route::post('/communityEvent/{event}/comment/create', 'store')->whereNumber('event')->middleware('throttle:posting')->name('communityEvent.comment.store');
        Route::get('/communityEvent/comment/deleteConfirm/{comment}', 'showDelete')->whereNumber('comment')->name('communityEvent.comment.delete.show');
        Route::post('/communityEvent/comment/delete/{comment}', 'delete')->whereNumber('comment')->name('communityEvent.comment.delete');
    });

    // Private messages. The four boxes plus a per-box show page. OpenPNE 3 keyed show by message id
    // with the box in the path (/message/read|check|checkDelete/:id); those URLs are preserved.
    // /message and /message/index land on the inbox.
    Route::prefix('message')->controller(MessageController::class)->group(function () {
        Route::get('/', 'index')->name('message.index');
        Route::get('/index', 'index');
        Route::get('/receiveList', 'receive')->name('message.receive');
        Route::get('/sendList', 'send')->name('message.send');
        Route::get('/draftList', 'draft')->name('message.draft');
        Route::get('/dustList', 'trash')->name('message.trash');
        // Compose (sendToFriend?id=), reply, and draft edit. OpenPNE 3 reached these through the
        // module/action fallback (no named route); the path shape is preserved.
        Route::get('/sendToFriend', 'compose')->name('message.compose');
        Route::post('/sendToFriend', 'store')->middleware('throttle:message-send')->name('message.compose.store');
        Route::get('/reply/{message}', 'reply')->whereNumber('message')->name('message.reply');
        Route::get('/edit/{message}', 'edit')->whereNumber('message')->name('message.draft.edit');
        // The final send passes through this POST; if compose ever gains autosave the per-member limit must be revisited.
        Route::post('/edit/{message}', 'update')->whereNumber('message')->middleware('throttle:message-send')->name('message.draft.update');
        Route::get('/read/{message}', 'showReceived')->whereNumber('message')->name('message.receive.show');
        Route::get('/check/{message}', 'showSent')->whereNumber('message')->name('message.send.show');
        Route::get('/checkDelete/{message}', 'showTrashed')->whereNumber('message')->name('message.trash.show');
        // Trash management. The move-to-trash and purge submits are CSRF form posts (OpenPNE 3
        // button_to), not bookmarkable URLs; the single purge has a GET confirm page. Restore and the
        // bulk list action have no named OpenPNE 3 route (reached via the module/action fallback).
        Route::post('/deleteReceiveMessage/{message}', 'trashReceived')->whereNumber('message')->name('message.receive.trash');
        Route::post('/deleteSendMessage/{message}', 'trashSent')->whereNumber('message')->name('message.send.trash');
        Route::post('/restore/{message}', 'restore')->whereNumber('message')->name('message.trash.restore');
        Route::get('/deleteConfirm/{message}', 'purgeConfirm')->whereNumber('message')->name('message.trash.purge.confirm');
        Route::post('/deleteComplete/{message}', 'purge')->whereNumber('message')->name('message.trash.purge');
        Route::post('/bulk', 'bulk')->name('message.bulk');
    });
});

// Retired Modern GET shapes that do not map onto their canonical URL by dropping the /m prefix:
// the RESTful community shapes redirect to the canonical (OpenPNE 3) shapes explicitly, ahead of
// the prefix-stripping catch-all. GET only — a retired POST shape has no path-rewritable canonical
// form and 404s (never persisted, so the only sources are stale in-flight forms). The original
// query string rides along, like the catch-all ('&' when the target already carries ?id=).
$mCompat = fn (string $target) => redirect()->to(
    $target.(($qs = request()->getQueryString()) === null ? '' : (str_contains($target, '?') ? '&' : '?').$qs), 308
);
Route::get('/m/community/joined', fn () => $mCompat('/community/joinList'));
Route::get('/m/community/topic/{topic}', fn (int $topic) => $mCompat("/communityTopic/{$topic}"))->whereNumber('topic');
Route::get('/m/community/topic/{topic}/edit', fn (int $topic) => $mCompat("/communityTopic/edit/{$topic}"))->whereNumber('topic');
Route::get('/m/community/event/{event}', fn (int $event) => $mCompat("/communityEvent/{$event}"))->whereNumber('event');
Route::get('/m/community/event/{event}/edit', fn (int $event) => $mCompat("/communityEvent/edit/{$event}"))->whereNumber('event');
Route::get('/m/community/event/{event}/members', fn (int $event) => $mCompat("/communityEvent/{$event}/memberList"))->whereNumber('event');
Route::get('/m/community/{community}/members', fn (int $community) => $mCompat("/community/member/list?id={$community}"))->whereNumber('community');
Route::get('/m/community/{community}/pending', fn (int $community) => $mCompat("/community/member/pending?id={$community}"))->whereNumber('community');
Route::get('/m/community/{community}/topic', fn (int $community) => $mCompat("/communityTopic/listCommunity/{$community}"))->whereNumber('community');
Route::get('/m/community/{community}/topic/new', fn (int $community) => $mCompat("/communityTopic/new/{$community}"))->whereNumber('community');
Route::get('/m/community/{community}/event', fn (int $community) => $mCompat("/communityEvent/listCommunity/{$community}"))->whereNumber('community');
Route::get('/m/community/{community}/event/new', fn (int $community) => $mCompat("/communityEvent/new/{$community}"))->whereNumber('community');

// Transition-era compat: the rest of the retired /m/ Modern URL space maps onto canonical URLs by
// dropping the prefix — one permanent catch-all (308 keeps the method for stale in-flight forms;
// the query string rides along). Carries no surface default — the canonical target resolves the
// surface from viewer state.
Route::any('/m/{path?}', function (Request $request, string $path = '') {
    $query = $request->getQueryString();

    return redirect()->to('/'.$path.($query === null ? '' : '?'.$query), 308);
})->where('path', '.*')->name('compat.m_prefix');
