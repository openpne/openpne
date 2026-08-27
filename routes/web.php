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

// Canonical OpenPNE 3 homepage (member/home). Resolves by surface: a Modern surface renders the
// latest issue, Classic the OpenPNE 3 gadget home. Guest-reachable, so it carries auth.session on
// its own: both surfaces serve member content here, and a session whose password hash is stale must
// not keep reading it (PublicRouteBoundaryTest).
Route::get('/', [HomeController::class, 'index'])->middleware('auth.session')->name('home');

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
// auth.session even though the route is public: without it a session whose password hash is stale
// keeps a non-null viewer here, and every gate downstream reads that viewer's clearance.
Route::middleware('auth.session')->group(function () {
    Route::get('/member/{member}', [ProfileController::class, 'show'])
        ->whereNumber('member')->name('member.profile.show');
    // OpenPNE 3 member_profile_raw alias (/member/profile/id/:id/*) → canonical /member/{id}.
    // OpenPNE 3's trailing splat matched extra path segments; capture and ignore them so the
    // whole legacy URL space redirects instead of 404ing past the id.
    Route::get('/member/profile/id/{member}/{tail?}', fn (int $member) => redirect()->route('member.profile.show', ['member' => $member]))
        ->whereNumber('member')->where('tail', '.*')->name('member.profile.raw_compat');
});

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

// Admin-issued two-factor reset landing. Token-gated and reachable whether or not the visitor is logged
// in (the locked-out member opens it as a guest), so it is neither guest- nor auth-restricted and lives
// on its own guest-reachable controller (auth boundary). GET renders the password form; the reset is the
// POST, so a mail scanner / prefetch cannot consume the token or clear a factor. NoReferrer keeps the URL
// secret out of the Referer header (URL secret + password, like the Fortify auth group). The POST also
// carries a per-token limiter (FortifyServiceProvider's mfa-reset) so distributed password guessing
// cannot pool onto one link; throttle:30,1 is the per-IP spray cap the GET and POST share.
Route::get('/member/mfa/reset/{token}', [MfaResetLinkController::class, 'form'])
    ->where('token', '[A-Za-z0-9]{40}')->middleware([NoReferrer::class, 'throttle:30,1'])->name('member.mfa.reset');
Route::post('/member/mfa/reset/{token}', [MfaResetLinkController::class, 'reset'])
    ->where('token', '[A-Za-z0-9]{40}')->middleware([NoReferrer::class, 'throttle:30,1', 'throttle:mfa-reset'])->name('member.mfa.reset.submit');

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

// Site policy pages, public: someone deciding whether to join reads them before they have an account.
// The OpenPNE 3 URLs (/userAgreement, /privacyPolicy and their /default/ twins) are permanent, so they
// 301 to the canonical pair rather than 404 — the moved-URL half of the URL-compatibility contract
// (App\Compat\Parities\PolicyRouteParity).
Route::get('/terms', [PolicyController::class, 'terms'])->name('policy.terms');
Route::get('/privacy', [PolicyController::class, 'privacy'])->name('policy.privacy');
Route::get('/userAgreement', fn () => redirect()->route('policy.terms', [], 301))->name('policy.terms_compat');
Route::get('/default/userAgreement', fn () => redirect()->route('policy.terms', [], 301))->name('policy.terms.default_compat');
Route::get('/privacyPolicy', fn () => redirect()->route('policy.privacy', [], 301))->name('policy.privacy_compat');
Route::get('/default/privacyPolicy', fn () => redirect()->route('policy.privacy', [], 301))->name('policy.privacy.default_compat');

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
    'theme_color' => brand_color() ?? BrandColor::DEFAULT,
    'icons' => [
        ['src' => app_icon_url(192), 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => app_icon_url(512), 'sizes' => '512x512', 'type' => 'image/png'],
    ],
], options: JSON_UNESCAPED_SLASHES)->header('Content-Type', 'application/manifest+json'))->name('webmanifest');

// Home-screen icon bytes derived from the branding favicon (App\Files\AppIcon). Public, like the
// manifest and the <head> links that point here. Both segments are constrained here rather than in
// the controller: an unlisted size must 404 before a typed int parameter can reject it as a 500.
Route::get('/app-icon/{token}/{size}.png', [AppIconController::class, 'show'])
    ->where(['token' => '[A-Za-z0-9_.-]+', 'size' => implode('|', AppIcon::SIZES)])
    ->name('app_icon');

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

// File byte delivery and its OpenPNE 3-compatible thumbnail twin, bound by the opaque `name` token.
// Login-free because the bytes a web-public diary or profile shows must render for the guest reading
// it; FilePolicy still gates every fetch by the owning entity, so a guest gets exactly the avatars,
// banners, explicit public assets and web-public diary/timeline images — everything else is 404,
// the same answer a member who may not read it gets. Disk backends stream through the app too
// (never a bare disk URL). auth.session for the same reason as the profile route above.
Route::middleware('auth.session')->group(function () {
    Route::get('/file/{file:name}', [FileController::class, 'show'])->name('file.show');

    // The size must be whitelisted (ImageTransform), so arbitrary sizes 404.
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
});

// A link card's picture, authorised through the post it appears under rather than through the file.
// The same card — and so the same image — can sit under a world-readable diary and a private one at
// once, so the URL names the post and LinkCardImageController re-derives permission from it on every
// request. Login-free for the same reason as the routes above: a web-public diary's card has to
// render for the guest reading it, and the controller answers 404 to everyone else.
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

// The read half of the diary module, guest-reachable as in OpenPNE 3 (diary/config/security.yml
// marks index/list/search/listMember/show `is_secure: false`). What a guest actually sees is the
// web-public (Open) tier, enforced per row and per query by DiaryAccess / DiaryVisibilityScope, and
// EnsureWebPublicDiaryEnabled closes the whole group once the SNS switches web-public diaries off.
// Writing, the friend feed and the id-less "my archive" stay in the auth group below.
// The feature gate precedes it: a switched-off diary 404s the guest too, and the web-public
// question only arises while the feature is on.
Route::middleware(['auth.session', EnsureFeatureEnabled::class.':diary', EnsureWebPublicDiaryEnabled::class])->group(function () {
    // OpenPNE 3 diary_index forwarded /diary to the list action; redirected here (URL preserved,
    // canonical URL is /diary/list).
    Route::get('/diary', fn () => redirect()->route('diary.list'))->name('diary.index_compat');

    // A guest must not learn from the response whether an id belongs to a member at all, so the
    // binding failure answers exactly as an author with no web-public diary does (login). A member
    // keeps the ordinary 404.
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

    // The shared `unread` badge counts alone (JSON), polled by an open Modern tab. Shell-wide, not
    // notification-owned: it carries the layer-1 counts too. See docs/internals/notifications.md.
    Route::get('/unread-counts', [UnreadCountsController::class, 'show'])->name('unread.counts');

    // The published issues (docs/internals/home-issues.md). Modern-only — OpenPNE 3 had no such
    // page — and no unit owns them: an issue is the site's own front page, and each band inside it
    // is gated by its own unit as it renders. `/` follows once the cutover lands.
    // The literal precedes the dated wildcard, and the day pattern is the diary archive's, so a
    // month or a day out of range is not a route rather than a page saying so.
    Route::get('/home/issues', [HomeIssueController::class, 'index'])->name('home.issues');
    Route::get('/home/{year}/{month}/{day}', [HomeIssueController::class, 'show'])
        ->where(['year' => '[12][0-9]{3}', 'month' => '0?[1-9]|1[0-2]', 'day' => '0?[1-9]|[12][0-9]|3[01]'])
        ->name('home.issue');

    // Server-side Markdown preview for the compose forms (diary / topic / event), rendered by the
    // same sanitized pipeline as a stored body. Throttled on its own limiter — a keystroke-driven
    // endpoint fires far more often than a post.
    Route::post('/compose/preview', [PreviewController::class, 'preview'])->middleware('throttle:preview')->name('compose.preview');
    // The member's Rich/Markdown/Plain input-method choice for the Modern compose forms, persisted by a
    // fire-and-forget fetch (204, no body). Unthrottled, matching the /member/config/* preference POSTs.
    Route::post('/compose/editor', [EditorPreferenceController::class, 'update'])->name('compose.editor');
    // The dashboard's group activity section, expanded. Modern-only (no OpenPNE 3 equivalent),
    // so it renders Inertia directly like /dashboard — not a surface twin.
    Route::get('/groups/recent', [HomeController::class, 'groupActivity'])
        ->middleware(EnsureFeatureEnabled::class.':group')->name('group.recent');

    // The per-event notification feed (layer 3). Served on both surfaces, though OpenPNE 3's
    // notification center had no PC page to be compatible with.
    Route::prefix('notifications')->controller(NotificationFeedController::class)->group(function () {
        Route::get('/', 'index')->name('notifications.index');
        Route::post('/read-all', 'readAll')->name('notifications.readAll');
        Route::post('/{notification}/open', 'open')->whereUuid('notification')->name('notifications.open');
    });

    // This device's push subscription, written by a fire-and-forget fetch (204, no body). The store
    // is capped per member in the controller; the throttle bounds the churn one member can cause
    // reaching that cap. Deleting is a POST like every other write here.
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
        // Two gates: the screen is a diary, but the lens it applies is the friend unit. Friendships
        // survive the toggle, so without the second gate this deep link keeps serving the lens.
        Route::get('/listFriend', 'listFriend')->middleware(EnsureFeatureEnabled::class.':friend')->name('diary.list_friend');
        Route::get('/new', 'new')->name('diary.new');
        Route::post('/create', 'store')->middleware('throttle:posting')->name('diary.store');
        Route::get('/edit/{diary}', 'edit')->whereNumber('diary')->name('diary.edit');
        Route::post('/update/{diary}', 'update')->whereNumber('diary')->middleware('throttle:posting')->name('diary.update');
        Route::get('/deleteConfirm/{diary}', 'showDelete')->whereNumber('diary')->name('diary.delete.show');
        Route::post('/delete/{diary}', 'delete')->whereNumber('diary')->name('diary.delete');
    });

    // OpenPNE 3 diaryComment module. create keys off the diary id; deleteConfirm/delete key
    // off the comment id (literal /diary/comment/* never collides with diary.show's numeric id).
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

    // OpenPNE 3 opTimelinePlugin: the cross-member home feed, a member's timeline, posting, and a
    // single-post permalink. Literal-prefix routes precede the {timelinePost} wildcard.
    Route::controller(TimelineController::class)->middleware(EnsureFeatureEnabled::class.':timeline')->group(function () {
        Route::get('/timeline', 'index')->name('timeline.index');
        Route::get('/timeline/rows', 'indexRows')->name('timeline.index.rows');
        Route::get('/member/{member}/timeline', 'member')->whereNumber('member')->name('timeline.member');
        Route::get('/member/{member}/timeline/rows', 'memberRows')->whereNumber('member')->name('timeline.member.rows');
        Route::get('/timeline/new', 'new')->name('timeline.new');
        // What the compose form's @mention picker reads (JSON), on a keystroke-rate limiter like the preview's.
        Route::get('/timeline/mention-candidates', 'mentionCandidates')->middleware('throttle:mention-search')->name('timeline.mention_candidates');
        // One hashtag's posts. The tag is percent-encoded in the URL and reaches the action decoded.
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

    // The community timeline is gone; group talk replaced it (docs/internals/group-talk.md). The
    // three URLs that reached it — the canonical one and OpenPNE 3's two spellings — redirect to the
    // conversation instead of 404ing, because they are in members' bookmarks and in OpenPNE 3 mail.
    //
    // Gated on `groupTalk`, NOT on `timeline`: the destination is a talk screen, so a site running
    // talk with the timeline unit switched off must still honour its own legacy URLs. GroupTalk
    // resolves the parent `group` gate itself, so one gate is the whole answer — and the names sit
    // under `group.talk.` so the unit that gates them is also the unit that owns them.
    //
    // The query rides along and the path id wins over a stray ?group=, as everywhere else. The
    // compose and POST routes are simply gone: there is no community timeline to write to, and a
    // redirect that dropped a member's draft on the floor would be worse than a 404.
    $talkRedirect = fn (Request $request, int $group) => redirect()
        ->route('group.talk.show', ['group' => $group] + $request->query());

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

    // OpenPNE 3 member/config — the member's own settings page. GET renders (Classic/Modern); each
    // section saves on its own POST so one change never rewrites another. The OpenPNE 3 access-block
    // category (/member/config?category=accessBlock) is redirected to the Block list inside show().
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

    // Modern-only detail pages for the consequential account changes (email/password/withdrawal)
    // and for the layout picker, which needs the room to describe what each look changes.
    // The settings page keeps a compact row per item; the forms live here, so a validation error
    // lands back on a short page where it is visible. Classic keeps its ?category= pages. Like
    // /community/recent these have no Classic twin, so no surface default / `.modern.` name.
    Route::get('/member/config/look', [MemberConfigController::class, 'editLook'])->name('member.config.look.edit');
    Route::get('/member/config/email', [MemberConfigController::class, 'editEmail'])->name('member.config.email.edit');
    Route::get('/member/config/password', [MemberConfigController::class, 'editPassword'])->name('member.config.password.edit');
    Route::get('/member/config/withdrawal', [MemberConfigController::class, 'editWithdrawal'])->name('member.config.withdrawal.edit');

    // Notification catalog opt-ins (see NotificationKind; Classic serves it
    // as ?category=notification). Modern edits on a detail page with per-toggle saves.
    Route::get('/member/config/notifications', [NotificationSettingsController::class, 'edit'])->name('member.config.notifications.edit');
    Route::post('/member/config/notifications', [NotificationSettingsController::class, 'update'])->name('member.config.notifications');
    // The global push pause switch, its own POST like every other member-config section.
    Route::post('/member/config/notifications/push', [NotificationSettingsController::class, 'updatePush'])->name('member.config.notifications.push');

    // A member's own AI accounts (OpenPNE 4-native; Classic serves the list as ?category=ai). The
    // owner check is MemberPolicy::manageAiAccount, which 404s so a member id naming someone else's
    // account reads exactly like one naming nothing.
    Route::controller(AiAccountController::class)->group(function () {
        Route::get('/member/config/ai', 'index')->name('member.config.ai');
        // Creating and deleting share one per-member budget, like the two-factor management POSTs.
        Route::post('/member/config/ai', 'store')->middleware('throttle:ai-manage')->name('member.config.ai.store');

        // The ownership gate is route middleware, not only the controller's Gate::authorize, because
        // it has to outrank the password-carrying FormRequests: a wrong password against a stranger's
        // account id must 404 like an unused id, not report a password error that says the account is
        // there.
        Route::middleware('can:manageAiAccount,member')->group(function () {
            Route::get('/member/config/ai/{member}', 'show')->whereNumber('member')->name('member.config.ai.show');
            // The account's face: its name, its self-introduction, its image. No re-auth — this is
            // the profile edit a person makes without proving themselves again, not the credential
            // pair below.
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
            // Group seats, so they carry the group unit's gate wherever they are declared
            // (FeatureRouteMiddlewarePinTest::DEPENDENCIES). The AI's membership is its own: it survives
            // the owner leaving the same group, and is given up only from here.
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

    // Group core (canonical / Classic). Nested plural: the literal routes precede the /{group}
    // wildcard, and {group} is digit-constrained, so a literal like /groups/edit can never be
    // captured as a group id. The OpenPNE 3 /community/* URLs are preserved by the redirects below.
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

        // Member management + immediate operations (appoint/demote sub-admin, drop member). The
        // operation endpoints name their target in member_id (GET confirm + POST submit like
        // join/quit); OpenPNE 3 reached them through the global module/action fallback.
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

    // The OpenPNE 3 /community/* GET URLs, preserved by redirect onto the canonical /groups/*
    // space (GroupRouteParity::compatRedirects()). OpenPNE 3 carried the group id as `?id=` on
    // most of them; the canonical takes it as a path segment, so each redirect lifts it across.
    // GET only — a POST submit is not a bookmarkable URL. Declared after the canonical block so
    // the wildcard /community/{group} cannot shadow a literal.
    Route::prefix('community')->middleware(EnsureFeatureEnabled::class.':group')->group(function () {
        // ?id= names the group; without it there is nothing to redirect to. It becomes the path
        // segment, so it is dropped from the query — the rest (member_id) rides along.
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

    // Group topic board (Classic only; Modern is none). The board is nested under its group
    // (/groups/{group}/topics); a thread is flat (/topics/{topic}), since a topic id names the
    // group already. The three-segment board path can never be captured by the /groups/{group}
    // wildcard above, and {topic} is digit-constrained, so the literal /topics/comments/* below
    // can never be read as a topic id. The gate is the board's own unit: Feature::GroupTopic
    // resolves its parent (group) itself, so a second gate would be redundant.
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

    // OpenPNE 3 communityTopicComment module. Posting keys off the topic id; the delete confirm and
    // submit key off the comment id, under the literal /topics/comments/ prefix.
    Route::controller(GroupTopicCommentController::class)->middleware(EnsureFeatureEnabled::class.':groupTopic')->group(function () {
        Route::post('/topics/{topic}/comments', 'store')->whereNumber('topic')->middleware('throttle:posting')->name('group.topics.comment.store');
        Route::get('/topics/comments/{comment}/delete', 'showDelete')->whereNumber('comment')->name('group.topics.comment.delete.show');
        Route::post('/topics/comments/{comment}/delete', 'delete')->whereNumber('comment')->name('group.topics.comment.delete');
    });

    // The OpenPNE 3 /communityTopic/* GET URLs, preserved by redirect onto the canonical space
    // (GroupTopicRouteParity::compatRedirects()). GET only — a POST submit is not a bookmarkable
    // URL. The query rides along: the board paginates, so a ?page=N bookmark must not reset to
    // page 1, and the path id wins over a stray ?group=/?topic=. Declared after the canonical
    // block so the wildcard /communityTopic/{topic} cannot shadow a literal.
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

    // A group's talk. Modern-only (no OpenPNE 3 equivalent), so it renders Inertia directly like
    // /groups/recent — not a surface twin, and no compat block. The page is nested under its group
    // like the boards; the JSON endpoints hang off the same path, so a conversation is reachable
    // from the group id alone. The gate is the unit's own: Feature::GroupTalk resolves its parent
    // (group) itself, so a second gate would be redundant.
    Route::middleware(EnsureFeatureEnabled::class.':groupTalk')->controller(GroupTalkController::class)->group(function () {
        Route::get('/groups/{group}/talk', 'show')->whereNumber('group')->name('group.talk.show');
        // One page either side of a cursor the client was handed: `after` for the poll, `before`
        // for "load older".
        Route::get('/groups/{group}/talk/messages', 'messages')->whereNumber('group')->name('group.talk.messages');
        // What the composer's @mention picker reads, on the keystroke-rate limiter the timeline's
        // picker uses. The literal segment can never be read as a message id ({message} is numeric).
        Route::get('/groups/{group}/talk/mention-candidates', 'mentionCandidates')->whereNumber('group')
            ->middleware('throttle:mention-search')->name('group.talk.mention_candidates');
        Route::post('/groups/{group}/talk', 'store')->whereNumber('group')
            ->middleware('throttle:posting')->name('group.talk.store');
        // "Read as far as this message" and the per-group mute. Both write the membership row, which
        // is where a member's own talk state lives; neither is throttled as posting — one is a
        // reading side effect and the other a preference.
        Route::post('/groups/{group}/talk/read', 'read')->whereNumber('group')->name('group.talk.read');
        Route::post('/groups/{group}/talk/mute', 'mute')->whereNumber('group')->name('group.talk.mute');
        // POST delete on its own URL, as the boards do; the path carries the group as well as the
        // message so the two can be checked against each other.
        Route::post('/groups/{group}/talk/messages/{message}/delete', 'delete')
            ->whereNumber(['group', 'message'])->name('group.talk.delete');
    });

    // Reactions hang off the message they are on, under the same unit gate. Add and remove are two
    // URLs rather than one toggle, so a retry or a double tap settles at the state that was asked
    // for; the reactor list is a separate GET because only an opened dialog needs the names.
    Route::middleware(EnsureFeatureEnabled::class.':groupTalk')->controller(GroupTalkReactionController::class)->group(function () {
        Route::post('/groups/{group}/talk/messages/{message}/reactions', 'store')
            ->whereNumber(['group', 'message'])->middleware('throttle:reaction')->name('group.talk.reactions.store');
        Route::post('/groups/{group}/talk/messages/{message}/reactions/delete', 'delete')
            ->whereNumber(['group', 'message'])->middleware('throttle:reaction')->name('group.talk.reactions.delete');
        Route::get('/groups/{group}/talk/messages/{message}/reactions', 'index')
            ->whereNumber(['group', 'message'])->name('group.talk.reactions.index');
    });

    // Group events (Classic only; Modern is none). Shaped like the topic board: the board is nested
    // under its group (/groups/{group}/events) and an event is flat (/events/{event}), since an
    // event id names the group already. {event} is digit-constrained, so the literal
    // /events/comments/* below can never be read as an event id. The gate is the board's own unit:
    // Feature::GroupEvent resolves its parent (group) itself, so a second gate would be redundant.
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

    // OpenPNE 3 communityEventComment module. Posting keys off the event id and carries the merged
    // RSVP form; the delete confirm and submit key off the comment id, under the literal
    // /events/comments/ prefix.
    Route::controller(GroupEventCommentController::class)->middleware(EnsureFeatureEnabled::class.':groupEvent')->group(function () {
        Route::post('/events/{event}/comments', 'store')->whereNumber('event')->middleware('throttle:posting')->name('group.events.comment.store');
        Route::get('/events/comments/{comment}/delete', 'showDelete')->whereNumber('comment')->name('group.events.comment.delete.show');
        Route::post('/events/comments/{comment}/delete', 'delete')->whereNumber('comment')->name('group.events.comment.delete');
    });

    // The OpenPNE 3 /communityEvent/* GET URLs, preserved by redirect onto the canonical space
    // (GroupEventRouteParity::compatRedirects()). GET only — a POST submit is not a bookmarkable
    // URL. The query rides along: the board paginates, so a ?page=N bookmark must not reset to
    // page 1, and the path id wins over a stray ?group=/?event=. Declared after the canonical
    // block so the wildcard /communityEvent/{event} cannot shadow a literal.
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

    // Private messages. The four boxes plus a per-box show page. OpenPNE 3 keyed show by message id
    // with the box in the path (/message/read|check|checkDelete/:id); those URLs are preserved.
    // /message and /message/index land on the inbox.
    Route::prefix('message')->middleware(EnsureFeatureEnabled::class.':directMessage')->controller(DirectMessageController::class)->group(function () {
        Route::get('/', 'index')->name('message.index');
        Route::get('/index', 'index')->name('message.index_compat');
        Route::get('/receiveList', 'receive')->name('message.receive');
        Route::get('/sendList', 'send')->name('message.send');
        Route::get('/draftList', 'draft')->name('message.draft');
        Route::get('/dustList', 'trash')->name('message.trash');
        // Compose (sendToFriend?id=), reply, and draft edit. OpenPNE 3 reached these through the
        // module/action fallback (no named route); the path shape is preserved.
        Route::get('/sendToFriend', 'compose')->name('message.compose');
        Route::post('/sendToFriend', 'store')->middleware('throttle:direct-message-send')->name('message.compose.store');
        Route::get('/reply/{message}', 'reply')->whereNumber('message')->name('message.reply');
        Route::get('/edit/{message}', 'edit')->whereNumber('message')->name('message.draft.edit');
        // The final send passes through this POST; if compose ever gains autosave the per-member limit must be revisited.
        Route::post('/edit/{message}', 'update')->whereNumber('message')->middleware('throttle:direct-message-send')->name('message.draft.update');
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

    // The same messages read as chat: the conversation list, and one conversation per member keyed
    // by that member rather than by a stored thread. Modern-only (chat has no OpenPNE 3
    // counterpart), so it renders Inertia directly like a group's talk, and it lives beside
    // /message/* rather than inside it — the mailbox URLs are OpenPNE 3's and stay exactly as they
    // are, redirecting here for a Modern viewer.
    Route::prefix('messages')->middleware(EnsureFeatureEnabled::class.':directMessage')->controller(ConversationController::class)->group(function () {
        Route::get('/', 'index')->name('message.chat.index');
        // Every literal is declared before {member} so none is ever read as a member id. The picker
        // is where a conversation with nothing in it yet is opened from, and its search rides the
        // keystroke-rate limiter the mention pickers use.
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
        // The same per-member limit the mailbox's compose carries: one member's sending budget, not
        // one screen's. The withdrawn bucket has no counterpart to deliver to and so no store route.
        Route::post('/{member}', 'store')->whereNumber('member')->middleware('throttle:direct-message-send')->name('message.chat.store');
        // The reader's own state, so it is not throttled as posting: reading is what opening the
        // screen does.
        Route::post('/{member}/read', 'read')->whereNumber('member')->name('message.chat.read');
        // Per-side and never a retraction, so it is the viewer's own state too rather than a write
        // anyone else sees (DeleteConversation).
        Route::post('/{member}/delete', 'delete')->whereNumber('member')->name('message.chat.delete');
    });
});

// Retired Modern GET shapes that do not map onto their canonical URL by dropping the /m prefix:
// the RESTful group shapes redirect to the canonical shapes explicitly, ahead of the
// prefix-stripping catch-all. GET only — a retired POST shape has no path-rewritable canonical
// form and 404s (never persisted, so the only sources are stale in-flight forms). The original
// query string rides along, like the catch-all ('&' when the target already carries ?id=).
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

// Transition-era compat: the rest of the retired /m/ Modern URL space maps onto canonical URLs by
// dropping the prefix — one permanent catch-all (308 keeps the method for stale in-flight forms;
// the query string rides along). Carries no surface default — the canonical target resolves the
// surface from viewer state.
Route::any('/m/{path?}', function (Request $request, string $path = '') {
    $query = $request->getQueryString();

    return redirect()->to('/'.$path.($query === null ? '' : '?'.$query), 308);
})->where('path', '.*')->name('compat.m_prefix');

// An unmatched URL 404s from the router, before the web group runs — no session, so no signed-in
// member, locale, or Classic shell to render it in. Routing it here makes it an ordinary request
// whose 404 goes through the same renderer as every other one.
Route::fallback(fn (Request $request) => ClassicErrorPage::respond($request, new NotFoundHttpException));
