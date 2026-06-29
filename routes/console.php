<?php

use App\Models\EmailChangeRequest;
use App\Models\RegistrationToken;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sweep expired pending tokens (see each model's prunable()): registration links and email-change links.
Schedule::command('model:prune', ['--model' => [RegistrationToken::class, EmailChangeRequest::class]])->daily();
