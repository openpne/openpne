<?php

declare(strict_types=1);

use App\Rules\NewEmailAddress;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Left in place, such a row would take the slot of the address the identifier collation folds it into (docs/internals/text-comparison.md, "Identifiers").
    public function up(): void
    {
        foreach (['registration_tokens' => 'email', 'email_change_requests' => 'new_email'] as $table => $column) {
            $ids = DB::table($table)->orderBy('id')->get(['id', $column])
                ->reject(fn (object $row): bool => NewEmailAddress::accepts((string) $row->{$column}))
                ->pluck('id');

            foreach ($ids->chunk(500) as $chunk) {
                DB::table($table)->whereIn('id', $chunk->all())->delete();
            }
        }
    }

    public function down(): void {}
};
