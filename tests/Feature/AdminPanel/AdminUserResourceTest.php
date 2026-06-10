<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Filament\Resources\AdminUsers\AdminUserResource;
use App\Filament\Resources\AdminUsers\Pages\CreateAdminUser;
use App\Filament\Resources\AdminUsers\Pages\EditAdminUser;
use App\Filament\Resources\AdminUsers\Pages\ListAdminUsers;
use App\Models\AdminUser;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin panel manages administrator accounts. Exercising the Filament Livewire components
 * runs the real form/table pipeline, including the conditional password validation and the
 * OpenPNE 3 delete/edit guards that a guard-level test would not catch.
 */
class AdminUserResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_list_renders_existing_administrators(): void
    {
        $this->actingAs(AdminUser::factory()->create(), 'admin');
        $others = AdminUser::factory()->count(2)->create();

        Livewire::test(ListAdminUsers::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($others);
    }

    public function test_create_persists_a_hashed_password(): void
    {
        $this->actingAs(AdminUser::factory()->create(), 'admin');

        Livewire::test(CreateAdminUser::class)
            ->fillForm([
                'username' => 'created',
                'password' => 'strong-pass-1',
                'password_confirmation' => 'strong-pass-1',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $admin = AdminUser::where('username', 'created')->firstOrFail();
        $this->assertTrue(Hash::check('strong-pass-1', $admin->password));
    }

    public function test_create_rejects_a_weak_password(): void
    {
        $this->actingAs(AdminUser::factory()->create(), 'admin');

        Livewire::test(CreateAdminUser::class)
            ->fillForm([
                'username' => 'weak',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);
    }

    public function test_create_rejects_a_mismatched_confirmation(): void
    {
        $this->actingAs(AdminUser::factory()->create(), 'admin');

        Livewire::test(CreateAdminUser::class)
            ->fillForm([
                'username' => 'mismatch',
                'password' => 'strong-pass-1',
                'password_confirmation' => 'different-pass-1',
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);
    }

    public function test_create_rejects_a_duplicate_username(): void
    {
        $this->actingAs(AdminUser::factory()->create(), 'admin');
        AdminUser::factory()->create(['username' => 'dupe']);

        Livewire::test(CreateAdminUser::class)
            ->fillForm([
                'username' => 'dupe',
                'password' => 'strong-pass-1',
                'password_confirmation' => 'strong-pass-1',
            ])
            ->call('create')
            ->assertHasFormErrors(['username']);
    }

    public function test_change_password_action_is_available_only_for_your_own_account(): void
    {
        $me = AdminUser::factory()->create(['username' => 'me']);
        $other = AdminUser::factory()->create(['username' => 'other']);
        $this->actingAs($me, 'admin');

        Livewire::test(EditAdminUser::class, ['record' => $me->getKey()])
            ->assertActionVisible('changePassword');

        Livewire::test(EditAdminUser::class, ['record' => $other->getKey()])
            ->assertActionHidden('changePassword');
    }

    public function test_change_password_requires_the_correct_current_password(): void
    {
        $me = AdminUser::factory()->create(['username' => 'me', 'password' => 'original-pass-1']);
        $this->actingAs($me, 'admin');

        Livewire::test(EditAdminUser::class, ['record' => $me->getKey()])
            ->callAction('changePassword', [
                'current_password' => 'wrong-current',
                'password' => 'new-strong-pass-1',
                'password_confirmation' => 'new-strong-pass-1',
            ])
            ->assertHasActionErrors(['current_password']);

        $this->assertTrue(Hash::check('original-pass-1', $me->fresh()->password));
    }

    public function test_change_password_rejects_a_mismatched_confirmation(): void
    {
        $me = AdminUser::factory()->create(['username' => 'me', 'password' => 'original-pass-1']);
        $this->actingAs($me, 'admin');

        Livewire::test(EditAdminUser::class, ['record' => $me->getKey()])
            ->callAction('changePassword', [
                'current_password' => 'original-pass-1',
                'password' => 'new-strong-pass-1',
                'password_confirmation' => 'different-pass-1',
            ])
            ->assertHasActionErrors(['password']);

        $this->assertTrue(Hash::check('original-pass-1', $me->fresh()->password));
    }

    public function test_change_password_succeeds_with_the_correct_current_password(): void
    {
        $me = AdminUser::factory()->create(['username' => 'me', 'password' => 'original-pass-1']);
        $this->actingAs($me, 'admin');

        Livewire::test(EditAdminUser::class, ['record' => $me->getKey()])
            ->callAction('changePassword', [
                'current_password' => 'original-pass-1',
                'password' => 'new-strong-pass-1',
                'password_confirmation' => 'new-strong-pass-1',
            ])
            ->assertHasNoActionErrors();

        $this->assertTrue(Hash::check('new-strong-pass-1', $me->fresh()->password));
        // The session's stored password hash is resynced so AuthenticateSession does not log the
        // operator out on the next request.
        $this->assertTrue(Hash::check('new-strong-pass-1', session('password_hash_admin')));
    }

    public function test_editing_the_username_does_not_change_the_password(): void
    {
        $me = AdminUser::factory()->create(['username' => 'me', 'password' => 'original-pass-1']);
        $this->actingAs($me, 'admin');

        Livewire::test(EditAdminUser::class, ['record' => $me->getKey()])
            ->fillForm(['username' => 'me-renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $me->refresh();
        $this->assertSame('me-renamed', $me->username);
        $this->assertTrue(Hash::check('original-pass-1', $me->password));
    }

    public function test_delete_is_guarded_for_the_primary_and_acting_administrators(): void
    {
        $primary = AdminUser::factory()->create(['id' => 1, 'username' => 'primary']);
        $acting = AdminUser::factory()->create(['username' => 'acting']);
        $other = AdminUser::factory()->create(['username' => 'other']);
        $this->actingAs($acting, 'admin');

        $this->assertFalse(AdminUserResource::canDelete($primary));
        $this->assertFalse(AdminUserResource::canDelete($acting));
        $this->assertTrue(AdminUserResource::canDelete($other));

        Livewire::test(ListAdminUsers::class)
            ->assertActionHidden(TestAction::make('delete')->table($primary))
            ->assertActionHidden(TestAction::make('delete')->table($acting))
            ->assertActionVisible(TestAction::make('delete')->table($other));
    }

    public function test_a_deletable_administrator_can_be_removed(): void
    {
        $acting = AdminUser::factory()->create(['username' => 'acting']);
        $other = AdminUser::factory()->create(['username' => 'other']);
        $this->actingAs($acting, 'admin');

        Livewire::test(ListAdminUsers::class)
            ->callAction(TestAction::make('delete')->table($other));

        $this->assertDatabaseMissing('admin_user', ['id' => $other->getKey()]);
    }
}
