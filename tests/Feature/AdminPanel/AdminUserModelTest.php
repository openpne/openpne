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

    public function test_admin_user_uses_the_singular_table_name(): void
    {
        // The model maps to OpenPNE 3's singular `admin_user`. Eloquent would
        // pluralize to `admin_users` by default, which would silently fail at
        // first query since the migration creates the singular name.
        $this->assertSame('admin_user', (new AdminUser)->getTable());
    }

    public function test_admin_username_is_unique_at_the_database_level(): void
    {
        AdminUser::factory()->create(['username' => 'opene']);

        $this->expectException(QueryException::class);

        AdminUser::factory()->create(['username' => 'opene']);
    }
}
