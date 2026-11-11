<?php

declare(strict_types=1);

use App\Support\OperatorContentConversion;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    // Kept apart from the migration adding banners.markdown: MySQL commits on ALTER TABLE, so a failed conversion there would leave the column behind and the migration unable to rerun.
    public function up(): void
    {
        (new OperatorContentConversion('run `php artisan migrate` again (the admin pages can edit this HTML only while OPENPNE_OPERATOR_RAW_HTML=true)'))->run(['sns_settings', 'banners', 'gadget_configs']);
    }

    public function down(): void {}
};
