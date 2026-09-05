<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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

            // Asked by time rather than by number so a repair that renumbers cannot change which
            // issue was published last.
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_issues');
    }
};
