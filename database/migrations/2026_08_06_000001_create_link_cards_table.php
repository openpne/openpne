<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Cached preview metadata for a URL a member pasted into a body: the title, description and image a
 * link card is drawn from. Keyed by the URL rather than by the body that mentions it, so a link many
 * people share is fetched once.
 *
 * Fetching is the expensive, failure-prone part, so this table is also the fetch bookkeeping:
 * `status` says whether the card is usable, `expires_at` when it goes stale, and `next_attempt_at`
 * is the lease a worker claims before going out to the network (an atomic conditional UPDATE), which
 * is what keeps a popular URL from being fetched by every worker at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_cards', function (Blueprint $table) {
            $table->id();

            // sha256 of the normalised URL (App\LinkCard\LinkUrl). Hashed rather than indexed
            // directly because a URL is longer than an index-friendly key, and the raw form is kept
            // alongside so the card can be re-fetched and rendered.
            $table->char('url_hash', 64)->unique();
            $table->text('url');

            // pending | ok | failed. Only `ok` renders; the others exist so a fetch is not retried
            // on every page view.
            $table->string('status', 16)->default('pending');

            $table->string('title', 300)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('site_name', 100)->nullable();
            $table->string('author_name', 100)->nullable();

            // Signed INT to match files.id — see the create_files_table header. foreignId() would
            // emit BIGINT UNSIGNED and the constraint would not create.
            $table->integer('image_file_id')->nullable();
            $table->foreign('image_file_id')->references('id')->on('files')->nullOnDelete();
            // What the container declared, kept as a record of what was fetched. **Nothing reads
            // them**, and nothing should: EXIF Orientation is not applied here, so a sideways-shot
            // JPEG has these two the wrong way round. Anything that lays the picture out — the shape
            // it is drawn in, the box reserved for it, the `w` descriptors — reads `files.width` /
            // `files.height`, which is the size the bytes render at (App\Files\ImageDimensions).
            $table->unsignedInteger('image_width')->nullable();
            $table->unsignedInteger('image_height')->nullable();

            // Drives the backoff, so a URL that is permanently gone stops being retried quickly.
            $table->unsignedTinyInteger('failure_count')->default(0);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            // The worker lease: a fetch is claimed by moving this forward conditionally, so two
            // workers that pick up the same URL cannot both go out to the network.
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
