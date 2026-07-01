<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Models\AdminUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_uses_the_plural_table_name(): void
    {
        // Eloquent infers `admin_users` from the model name; the singular OpenPNE 3
        // `admin_user` is kept as the upgrade source so both coexist in a
        // same-database upgrade.
        $this->assertSame('admin_users', (new AdminUser)->getTable());
    }

    public function test_admin_username_is_unique_at_the_database_level(): void
    {
        AdminUser::factory()->create(['username' => 'opene']);

        $this->expectException(QueryException::class);

        AdminUser::factory()->create(['username' => 'opene']);
    }
}
