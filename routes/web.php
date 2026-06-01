<?php

use App\Features\Block\BlockController;
use App\Features\Diary\DiaryController;
use App\Features\Friend\FriendController;
use App\Features\Member\MemberAvatarController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\ImageController;
use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Auth::check() ? redirect('/dashboard') : redirect('/login');
});

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
            // Allow the `_`/`-` that migrated OpenPNE 3 file names carry (e.g. m_42_..._jpg);
            // new names are Str::random alnum. `.` stays out — it separates name from ext.
            'name' => '[A-Za-z0-9_-]+',
            'ext' => 'jpg|png|gif|webp',
        ])
        ->name('image.show');
});
