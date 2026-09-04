<?php

use App\Features\Home\Actions\PublishHomeIssue;
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

// In the background: this can run for minutes, and a per-minute systemd timer skips rather than
// queues while the last `schedule:run` is still running, so the daily sweeps would go missing.
Schedule::command('openpne:prune-link-cards')->weeklyOn(0, '3:10')->runInBackground();

// Foreground and unguarded against a double run: it costs seconds, and the unique on `issue_date`
// makes an overlapping second run write nothing.
Schedule::command('openpne:publish-home-issue')->dailyAt(PublishHomeIssue::TIME);
