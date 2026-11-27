<?php

namespace Tests\Concerns;

use App\Auth\AdminEntry;
use App\Models\Member;

/**
 * The one way a test that is not about the administrator role itself becomes an administrator. Signed
 * in on the member guard and through the panel's entry, as the panel reads them; a Livewire::test call
 * skips the panel's gate, which the HTTP tests pin.
 */
trait ActsAsAdmin
{
    protected function createAdmin(): Member
    {
        return Member::factory()->administrator()->create();
    }

    protected function actingAsAdmin(?Member $admin = null): static
    {
        $admin ??= $this->createAdmin();

        return $this->actingAs($admin, 'member')->withSession([AdminEntry::SESSION_KEY => AdminEntry::mark($admin)]);
    }

    protected function signOutAdmin(): void
    {
        auth('member')->logout();
    }
}
