<?php

use App\Console\ScheduleOffset;
use App\Features\Home\Actions\PublishHomeIssue;
use App\Models\EmailChangeRequest;
use App\Models\MfaResetRequest;
use App\Models\RegistrationToken;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

try {
    $offset = ScheduleOffset::minutes(config('openpne.schedule.offset_minutes'));
} catch (InvalidArgumentException $invalid) {
    $offset = null;
    // Only the scheduler's commands refuse, matched on the name Symfony resolved, because this file loads for every command, the queue worker included.
    Event::listen(CommandStarting::class, function (CommandStarting $event) use ($invalid): void {
        if (str_starts_with($event->command, 'schedule:')) {
            throw $invalid;
        }
    });
}

if ($offset !== null) {
    // Sweep expired pending tokens (see each model's prunable()): registration, email-change, and MFA-reset links.
    Schedule::command('model:prune', ['--model' => [RegistrationToken::class, EmailChangeRequest::class, MfaResetRequest::class]])->cron(ScheduleOffset::daily('00:00', $offset));

    // Spent OAuth codes, tokens and never-used clients; also run by TickHousekeeping.
    Schedule::command('openpne:prune-oauth')->cron(ScheduleOffset::daily('00:00', $offset));

    // In the background: this can run for minutes, and a per-minute systemd timer skips rather than
    // queues while the last `schedule:run` is still running, so the daily sweeps would go missing.
    Schedule::command('openpne:prune-link-cards')->cron(ScheduleOffset::weekly(0, '03:10', $offset))->runInBackground();

    // Foreground and unguarded against a double run: it costs seconds, and the unique on `issue_date`
    // makes an overlapping second run write nothing.
    Schedule::command('openpne:publish-home-issue')->cron(ScheduleOffset::daily(PublishHomeIssue::TIME, $offset));
}
