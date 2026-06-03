<?php

use App\Features\Block\BlockController;
use App\Features\Diary\DiaryCommentController;
use App\Features\Diary\DiaryController;
use App\Features\Friend\FriendController;
use App\Features\Home\HomeController;
use App\Features\Member\MemberAvatarController;
use App\Features\Member\MemberSearchController;
use App\Features\Profile\ProfileController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\ImageController;
use App\Http\Middleware\SetLocale;
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

Route::middleware('auth')->group(function () {
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
        Route::get('/listMember/{member?}', 'listMember')->whereNumber('member')->name('diary.list_member');
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
        Route::get('/listMember/{member?}', 'listMember')->whereNumber('member')->defaults('surface', 'modern')->name('diary.modern.list_member');
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
});
