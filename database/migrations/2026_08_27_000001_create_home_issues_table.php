<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One published front page: the window it covered and the number it went out as.
 *
 * `issue_date` is unique because it IS the idempotency guarantee. The publisher runs on a schedule,
 * and a retry, a double trigger or two hosts firing at once must not produce a second issue for the
 * same day; the database decides that rather than a lock, so the losing run fails its insert and has
 * nothing to unwind. `number` is unique for a plainer reason: two issues carrying the same running
 * number is a visible lie.
 *
 * The window is stored rather than recomputed from the schedule: `window_start` is the previous
 * issue's `published_at` (exclusive) and `published_at` is this one's (inclusive). An issue that ran
 * late therefore still covers exactly what the one before it did not, and the next run reads its
 * lower bound from a row instead of assuming the schedule held.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_issues', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique();
            $table->date('issue_date')->unique();
            $table->timestamp('window_start');
            $table->timestamp('published_at');
            $table->timestamps();

            // The next run's first question: which issue was published last, and therefore where its
            // window starts. Asked by time rather than by number so a repair that renumbers cannot
            // change the answer.
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_issues');
    }
};
