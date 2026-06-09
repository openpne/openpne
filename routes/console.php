<?php

use App\Models\RegistrationToken;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sweep expired pending-registration rows (see RegistrationToken::prunable()).
Schedule::command('model:prune', ['--model' => [RegistrationToken::class]])->daily();
