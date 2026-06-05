<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Communities (successor of the OpenPNE 3 `community` table).
 *
 * `description` and `register_policy` are flattened from OpenPNE 3's community_config KV table
 * onto typed columns (the same treatment member_config gets), since they drive behaviour. The
 * remaining config (is_default, notification mail flags) defers to later features.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communities', function (Blueprint $table) {
            $table->id();
            // OpenPNE 3 community.name is varchar(64) UNIQUE.
            $table->string('name', 64)->unique();
            $table->text('description')->nullable();
            // Join policy: Open=1 (immediate) / Approval=2 (admin approves). Default Open matches
            // the OpenPNE 3 community_config register_policy default ("open"). Frozen literal (not
            // JoinPolicy::Open->value) so a later enum change cannot drift this migration's default.
            $table->unsignedTinyInteger('register_policy')->default(1); // JoinPolicy::Open
            $table->foreignId('community_category_id')->nullable()->constrained('community_categories')->nullOnDelete();
            // Successor of OpenPNE 3's single community_member_position 'admin_confirm' row: the
            // pending target of an admin transfer. Written only by the (deferred) transfer handshake.
            $table->foreignId('pending_admin_member_id')->nullable()->constrained('members')->nullOnDelete();
            // Top image. Signed INT to match files.id (see create_files_table); nullable and SET
            // NULL on file delete, mirroring the OpenPNE 3 community.file_id FK.
            $table->integer('file_id')->nullable();
            $table->timestamps();

            $table->foreign('file_id')->references('id')->on('files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communities');
    }
};
