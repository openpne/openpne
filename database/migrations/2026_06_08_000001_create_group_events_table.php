<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            // Keep the event when its author is deleted (OpenPNE 3 Member onDelete: set null).
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            // OpenPNE 3 name/body/open_date_comment/area are Doctrine `type: string` with no length =
            // MySQL TEXT, so TEXT here keeps migrated content from being truncated.
            $table->text('name');
            $table->text('body');
            // OpenPNE 3's "last edited" bump, carried for upgrade fidelity and null until OpenPNE 3
            // first bumps it; the board here orders by updated_at.
            $table->dateTime('event_updated_at')->nullable();
            // OpenPNE 3 stores a date only (its form widget is date-only); the time of day is the
            // free-text open_date_comment beside it.
            $table->dateTime('open_date');
            // NOT NULL in OpenPNE 3, where an empty form field stores '' rather than null.
            $table->text('open_date_comment');
            $table->text('area');
            $table->dateTime('application_deadline')->nullable();
            $table->integer('capacity')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_events');
    }
};
