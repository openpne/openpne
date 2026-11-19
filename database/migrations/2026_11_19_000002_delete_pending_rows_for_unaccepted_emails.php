<?php

declare(strict_types=1);

use App\Rules\NewEmailAddress;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Left in place, such a row would take the slot of the address the identifier collation folds it into (docs/internals/text-comparison.md, "Identifiers").
    public function up(): void
    {
        foreach (['registration_tokens' => 'email', 'email_change_requests' => 'new_email'] as $table => $column) {
            DB::table($table)->select(['id', $column])->chunkById(500, function (Collection $rows) use ($table, $column): void {
                $ids = $rows->reject(fn (object $row): bool => NewEmailAddress::accepts((string) $row->{$column}))->pluck('id');
                if ($ids->isNotEmpty()) {
                    DB::table($table)->whereIn('id', $ids->all())->delete();
                }
            });
        }
    }

    public function down(): void {}
};
