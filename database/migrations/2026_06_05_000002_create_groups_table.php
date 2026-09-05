<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            // OpenPNE 3 community.name is varchar(64) UNIQUE.
            $table->string('name', 64)->unique();
            $table->text('description')->nullable();
            // Frozen literal (not JoinPolicy::Open->value) so a later enum change cannot drift this
            // default.
            $table->unsignedTinyInteger('register_policy')->default(1); // JoinPolicy::Open
            $table->foreignId('group_category_id')->nullable()->constrained('group_categories')->nullOnDelete();
            $table->foreignId('pending_admin_member_id')->nullable()->constrained('members')->nullOnDelete();
            // Signed INT to match files.id; SET NULL on file delete mirrors the OpenPNE 3
            // community.file_id FK.
            $table->integer('file_id')->nullable();
            $table->timestamps();

            $table->foreign('file_id')->references('id')->on('files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
