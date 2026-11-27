<?php

namespace Tests\Concerns;

use App\Models\AdminUser;

/** The one way a test that is not about the administrator account itself becomes an administrator. */
trait ActsAsAdmin
{
    protected function createAdmin(): AdminUser
    {
        return AdminUser::factory()->create();
    }

    /** Signs in to the admin panel as $admin, or as a fresh administrator. */
    protected function actingAsAdmin(?AdminUser $admin = null): static
    {
        return $this->actingAs($admin ?? $this->createAdmin(), 'admin');
    }

    protected function signOutAdmin(): void
    {
        auth('admin')->logout();
    }
}
