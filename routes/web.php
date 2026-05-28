<?php

use App\Features\Friend\FriendController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Auth::check() ? redirect('/dashboard') : redirect('/login');
});

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
        Route::get('/unlink/{member}', 'showUnlink')->defaults('surface', 'modern')->name('friend.modern.unlink.show');
    });
});
