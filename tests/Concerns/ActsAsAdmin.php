<?php

namespace Tests\Concerns;

use App\Models\Member;

/**
 * The one way a test that is not about the administrator role itself becomes an administrator. Signed
 * in on the member guard, as the panel reads it; a Livewire::test call skips the panel's gate, which
 * the HTTP tests pin.
 */
trait ActsAsAdmin
{
    protected function createAdmin(): Member
    {
        return Member::factory()->administrator()->create();
    }

    protected function actingAsAdmin(?Member $admin = null): static
    {
        return $this->actingAs($admin ?? $this->createAdmin(), 'member');
    }

    protected function signOutAdmin(): void
    {
        auth('member')->logout();
    }
}
