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
use App\Features\Member\InviteController;
use App\Features\Member\MemberAvatarController;
use App\Features\Member\MemberSearchController;
use App\Features\Profile\ProfileController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\ImageController;
use App\Http\Middleware\AsBackgroundFetch;
use App\Http\Middleware\EnsureMemberInviteAllowed;
use App\Http\Middleware\EnsureOpenRegistration;
use App\Http\Middleware\NoReferrer;
use App\Http\Middleware\SetLocale;
use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Canonical OpenPNE 3 homepage (member/home). Resolves by surface: a Classic-default install
// renders the Classic home, a Modern-default one redirects to the Inertia dashboard.
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
// and reloads, so a 204 is enough.
Route::post('/locale/session', function (Request $request) {
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
Route::get('/m/member/{member}', [ProfileController::class, 'show'])
    ->whereNumber('member')->defaults('surface', 'modern')->name('member.profile.modern.show');
// OpenPNE 3 member_profile_raw alias (/member/profile/id/:id/*) → canonical /member/{id}.
// OpenPNE 3's trailing splat matched extra path segments; capture and ignore them so the
// whole legacy URL space redirects instead of 404ing past the id.
Route::get('/member/profile/id/{member}/{tail?}', fn (int $member) => redirect()->route('member.profile.show', ['member' => $member]))
    ->whereNumber('member')->where('tail', '.*')->name('member.profile.raw_compat');

// OpenPNE 3 served login at /member/login/*; login moved to Fortify's /login. Preserve the legacy
// URL with a redirect (guest-reachable, so outside the auth group). The {member} route above is
// whereNumber, so /member/login never matches it regardless of order.
Route::get('/member/login/{tail?}', fn () => redirect()->route('login'))
    ->where('tail', '.*')->name('member.login_compat');

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
    Route::get('/dashboard', fn () => Inertia::render('dashboard'))->name('dashboard');

    Route::prefix('friend')->controller(FriendController::class)->group(function () {
        Route::get('/list', 'list')->name('friend.list');
        Route::get('/manage', 'manage')->name('friend.manage');
        Route::get('/link', 'showLink')->name('friend.link.show');
        Route::post('/link', 'submitLink')->name('friend.link');
        Route::post('/accept', 'submitAccept')->name('friend.accept');
        Route::post('/reject', 'submitReject')->name('friend.reject');
        Route::get('/unlink/{member}', 'showUnlink')->name('friend.unlink.show');
        Route::post('/unlink/{member}', 'submitUnlink')->name('friend.unlink.submit');
    });

    Route::prefix('m/friend')->controller(FriendController::class)->group(function () {
        Route::get('/list', 'list')->defaults('surface', 'modern')->name('friend.modern.list');
        Route::get('/manage', 'manage')->defaults('surface', 'modern')->name('friend.modern.manage');
        Route::get('/link', 'showLink')->defaults('surface', 'modern')->name('friend.modern.link.show');
        Route::post('/link', 'submitLink')->defaults('surface', 'modern')->name('friend.modern.link');
        Route::post('/accept', 'submitAccept')->defaults('surface', 'modern')->name('friend.modern.accept');
        Route::post('/reject', 'submitReject')->defaults('surface', 'modern')->name('friend.modern.reject');
        Route::get('/unlink/{member}', 'showUnlink')->defaults('surface', 'modern')->name('friend.modern.unlink.show');
        Route::post('/unlink/{member}', 'submitUnlink')->defaults('surface', 'modern')->name('friend.modern.unlink.submit');
    });

    Route::prefix('block')->controller(BlockController::class)->group(function () {
        Route::get('/list', 'list')->name('block.list');
        Route::get('/add', 'showAdd')->name('block.add.show');
        Route::post('/add', 'submitAdd')->name('block.add');
        Route::get('/remove/{member}', 'showRemove')->name('block.remove.show');
        Route::post('/remove/{member}', 'submitRemove')->name('block.remove.submit');
    });

    Route::prefix('m/block')->controller(BlockController::class)->group(function () {
        Route::get('/list', 'list')->defaults('surface', 'modern')->name('block.modern.list');
        Route::get('/add', 'showAdd')->defaults('surface', 'modern')->name('block.modern.add.show');
        Route::post('/add', 'submitAdd')->defaults('surface', 'modern')->name('block.modern.add');
        Route::get('/remove/{member}', 'showRemove')->defaults('surface', 'modern')->name('block.modern.remove.show');
        Route::post('/remove/{member}', 'submitRemove')->defaults('surface', 'modern')->name('block.modern.remove.submit');
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
        Route::post('/create', 'store')->name('diary.store');
        Route::get('/edit/{diary}', 'edit')->whereNumber('diary')->name('diary.edit');
        Route::post('/update/{diary}', 'update')->whereNumber('diary')->name('diary.update');
        Route::get('/deleteConfirm/{diary}', 'showDelete')->whereNumber('diary')->name('diary.delete.show');
        Route::post('/delete/{diary}', 'delete')->whereNumber('diary')->name('diary.delete');
        Route::get('/{diary}', 'show')->whereNumber('diary')->name('diary.show');
    });

    // OpenPNE 3 diaryComment module. create keys off the diary id; deleteConfirm/delete key
    // off the comment id (literal /diary/comment/* never collides with diary.show's numeric id).
    Route::controller(DiaryCommentController::class)->group(function () {
        Route::post('/diary/{diary}/comment/create', 'store')->whereNumber('diary')->name('diary.comment.store');
        Route::get('/diary/comment/deleteConfirm/{comment}', 'showDelete')->whereNumber('comment')->name('diary.comment.delete.show');
        Route::post('/diary/comment/delete/{comment}', 'delete')->whereNumber('comment')->name('diary.comment.delete');
    });

    Route::prefix('m/diary')->controller(DiaryController::class)->group(function () {
        Route::get('/search', 'search')->defaults('surface', 'modern')->name('diary.modern.search');
        Route::get('/list', 'list')->defaults('surface', 'modern')->name('diary.modern.list');
        Route::get('/listFriend', 'listFriend')->defaults('surface', 'modern')->name('diary.modern.list_friend');
        Route::get('/listMember/{member?}', 'listMember')->whereNumber('member')->defaults('surface', 'modern')->name('diary.modern.list_member');
        Route::get('/listMember/{member}/{year}/{month}/{day?}', 'listMemberArchive')
            ->where(['member' => '[0-9]+', 'year' => '[12][0-9]{3}', 'month' => '0?[1-9]|1[0-2]', 'day' => '0?[1-9]|[12][0-9]|3[01]'])
            ->defaults('surface', 'modern')->name('diary.modern.list_member.archive');
        Route::get('/new', 'new')->defaults('surface', 'modern')->name('diary.modern.new');
        Route::post('/create', 'store')->defaults('surface', 'modern')->name('diary.modern.store');
        Route::get('/edit/{diary}', 'edit')->whereNumber('diary')->defaults('surface', 'modern')->name('diary.modern.edit');
        Route::post('/update/{diary}', 'update')->whereNumber('diary')->defaults('surface', 'modern')->name('diary.modern.update');
        Route::get('/deleteConfirm/{diary}', 'showDelete')->whereNumber('diary')->defaults('surface', 'modern')->name('diary.modern.delete.show');
        Route::post('/delete/{diary}', 'delete')->whereNumber('diary')->defaults('surface', 'modern')->name('diary.modern.delete');
        Route::get('/{diary}', 'show')->whereNumber('diary')->defaults('surface', 'modern')->name('diary.modern.show');
    });

    Route::controller(DiaryCommentController::class)->group(function () {
        Route::post('/m/diary/{diary}/comment/create', 'store')->whereNumber('diary')->defaults('surface', 'modern')->name('diary.modern.comment.store');
        Route::get('/m/diary/comment/deleteConfirm/{comment}', 'showDelete')->whereNumber('comment')->defaults('surface', 'modern')->name('diary.modern.comment.delete.show');
        Route::post('/m/diary/comment/delete/{comment}', 'delete')->whereNumber('comment')->defaults('surface', 'modern')->name('diary.modern.comment.delete');
    });

    // OpenPNE 3 compatibility: access block lived at /member/config?category=accessBlock.
    // The member config module is not ported yet, so resolve just that category to the
    // canonical Block list. 302 (not 301) because a future member config module will
    // reclaim this URL. Folded into that module once it exists.
    Route::get('/member/config', function (Request $request) {
        abort_unless($request->query('category') === 'accessBlock', 404);

        return redirect()->route('block.list');
    })->name('member.config.access_block_compat');

    Route::prefix('member')->controller(MemberAvatarController::class)->group(function () {
        Route::get('/avatar', 'edit')->name('member.avatar.edit');
        Route::post('/avatar', 'update')->name('member.avatar.update');
        Route::delete('/avatar', 'destroy')->name('member.avatar.destroy');
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
    Route::get('/m/member/search', [MemberSearchController::class, 'search'])
        ->defaults('surface', 'modern')->name('member.modern.search');

    // OpenPNE 3 member/editProfile (/member/edit/profile): the member edits their own profile
    // fields + per-value visibility. GET renders, POST saves — same URL as OpenPNE 3.
    Route::get('/member/edit/profile', [ProfileController::class, 'edit'])->name('member.profile.edit');
    Route::post('/member/edit/profile', [ProfileController::class, 'update'])->name('member.profile.update');
    Route::get('/m/member/edit/profile', [ProfileController::class, 'edit'])
        ->defaults('surface', 'modern')->name('member.profile.modern.edit');
    Route::post('/m/member/edit/profile', [ProfileController::class, 'update'])
        ->defaults('surface', 'modern')->name('member.profile.modern.update');

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

    // Community core (Classic only; Modern is none in Phase A — no /m/community/*). The literal
    // routes precede the /{community} wildcard, and {community} is digit-constrained, so a
    // literal like /community/search can never be captured as a community id.
    Route::prefix('community')->controller(CommunityController::class)->group(function () {
        Route::get('/search', 'search')->name('community.search');
        Route::get('/joinList', 'listMine')->name('community.list_mine');
        // Single endpoint for new+edit and create+update (?id= switches), as in OpenPNE 3.
        Route::get('/edit', 'edit')->name('community.edit');
        Route::post('/edit', 'save')->name('community.save');
        // join / quit: GET confirm, POST submit (community id via ?id=).
        Route::get('/join', 'showJoin')->name('community.join.show');
        Route::post('/join', 'join')->name('community.join');
        Route::get('/quit', 'showQuit')->name('community.quit.show');
        Route::post('/quit', 'quit')->name('community.quit');
        // Member roster + pending-member approval.
        Route::get('/member/list', 'members')->name('community.members');
        Route::get('/member/pending', 'pendingMembers')->name('community.members.pending');
        Route::post('/member/approve', 'approve')->name('community.members.approve');
        Route::post('/member/decline', 'decline')->name('community.members.decline');
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
        Route::post('/create/{community}', 'store')->whereNumber('community')->name('communityTopic.store');
        Route::get('/edit/{topic}', 'edit')->whereNumber('topic')->name('communityTopic.edit');
        Route::post('/update/{topic}', 'update')->whereNumber('topic')->name('communityTopic.update');
        Route::get('/deleteConfirm/{topic}', 'showDelete')->whereNumber('topic')->name('communityTopic.delete.show');
        Route::post('/delete/{topic}', 'delete')->whereNumber('topic')->name('communityTopic.delete');
        Route::get('/{topic}', 'show')->whereNumber('topic')->name('communityTopic.show');
    });

    // communityTopicComment module. create keys off the topic id; deleteConfirm/delete key off the
    // comment id (literal /communityTopic/comment/* never collides with the numeric topic show).
    Route::controller(CommunityTopicCommentController::class)->group(function () {
        Route::post('/communityTopic/{topic}/comment/create', 'store')->whereNumber('topic')->name('communityTopic.comment.store');
        Route::get('/communityTopic/comment/deleteConfirm/{comment}', 'showDelete')->whereNumber('comment')->name('communityTopic.comment.delete.show');
        Route::post('/communityTopic/comment/delete/{comment}', 'delete')->whereNumber('comment')->name('communityTopic.comment.delete');
    });

    // Community events (Classic only; Modern is none). Same literal-before-wildcard rule as the topic
    // board: listCommunity/new/create take a community id, the rest an event id, and {event} is
    // digit-constrained, so /communityEvent/memberList-style literals can never be read as an event id.
    Route::prefix('communityEvent')->controller(CommunityEventController::class)->group(function () {
        Route::get('/listCommunity/{community}', 'index')->whereNumber('community')->name('communityEvent.index');
        Route::get('/new/{community}', 'new')->whereNumber('community')->name('communityEvent.new');
        Route::post('/create/{community}', 'store')->whereNumber('community')->name('communityEvent.store');
        Route::get('/edit/{event}', 'edit')->whereNumber('event')->name('communityEvent.edit');
        Route::post('/update/{event}', 'update')->whereNumber('event')->name('communityEvent.update');
        Route::get('/deleteConfirm/{event}', 'showDelete')->whereNumber('event')->name('communityEvent.delete.show');
        Route::post('/delete/{event}', 'delete')->whereNumber('event')->name('communityEvent.delete');
        Route::get('/{event}/memberList', 'memberList')->whereNumber('event')->name('communityEvent.member_list');
        Route::get('/{event}', 'show')->whereNumber('event')->name('communityEvent.show');
    });

    // communityEventComment module. create keys off the event id and carries the merged RSVP form;
    // deleteConfirm/delete key off the comment id (literal /communityEvent/comment/* never collides
    // with the numeric event show).
    Route::controller(CommunityEventCommentController::class)->group(function () {
        Route::post('/communityEvent/{event}/comment/create', 'store')->whereNumber('event')->name('communityEvent.comment.store');
        Route::get('/communityEvent/comment/deleteConfirm/{comment}', 'showDelete')->whereNumber('comment')->name('communityEvent.comment.delete.show');
        Route::post('/communityEvent/comment/delete/{comment}', 'delete')->whereNumber('comment')->name('communityEvent.comment.delete');
    });
});
