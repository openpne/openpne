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

// Link cards no body points at any more, and the image bytes they hold. Weekly, and off the hour the
// daily sweep runs: nothing depends on an orphan going promptly, and the sweep walks every card.
Schedule::command('openpne:prune-link-cards')->weeklyOn(0, '3:10');
