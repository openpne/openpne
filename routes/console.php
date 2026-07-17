<?php

use App\Models\EmailChangeRequest;
use App\Models\MfaResetRequest;
use App\Models\RegistrationToken;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sweep expired pending tokens (see each model's prunable()): registration, email-change, and MFA-reset links.
Schedule::command('model:prune', ['--model' => [RegistrationToken::class, EmailChangeRequest::class, MfaResetRequest::class]])->daily();
