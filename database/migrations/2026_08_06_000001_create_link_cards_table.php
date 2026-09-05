<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_cards', function (Blueprint $table) {
            $table->id();

            // sha256 of the normalised URL (App\LinkCard\LinkUrl), which is longer than an
            // index-friendly key.
            $table->char('url_hash', 64)->unique();
            $table->text('url');

            $table->string('status', 16)->default('pending');

            $table->string('title', 300)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('site_name', 100)->nullable();
            $table->string('author_name', 100)->nullable();

            // Signed INT to match files.id.
            $table->integer('image_file_id')->nullable();
            $table->foreign('image_file_id')->references('id')->on('files')->nullOnDelete();
            // What the container declared, before EXIF Orientation, and read by nothing
            // (docs/internals/link-cards.md, "Two shapes, chosen by the picture").
            $table->unsignedInteger('image_width')->nullable();
            $table->unsignedInteger('image_height')->nullable();

            $table->unsignedTinyInteger('failure_count')->default(0);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('link_cards', function (Blueprint $table): void {
            // Dropped before the table so the FK does not outlive its index on InnoDB, which adopts
            // the constraint's backing index and then refuses to drop it (errno 1553).
            $table->dropForeign(['image_file_id']);
        });

        Schema::dropIfExists('link_cards');
    }
};
