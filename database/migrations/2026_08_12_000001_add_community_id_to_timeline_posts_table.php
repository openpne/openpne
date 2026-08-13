<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Group scope for a timeline post (successor of OpenPNE 3 activity_data.foreign_table='community'
 * + foreign_id). Null is an ordinary SNS-wide post; set means the post belongs to that community's
 * timeline and is excluded from every SNS-wide feed. A reply inherits its parent's value, as
 * OpenPNE 3 does by carrying the parent's target through the reply POST.
 *
 * No composite index yet: community_id alone narrows to one community, leaving a filesort over that
 * community's posts. (community_id, created_at, id) is the next step if profiling shows it, weighed
 * against a second index's write and storage cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timeline_posts', function (Blueprint $table) {
            $table->foreignId('community_id')->nullable()->constrained('groups')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('timeline_posts', function (Blueprint $table) {
            $table->dropForeign(['community_id']);
            $table->dropColumn('community_id');
        });
    }
};
