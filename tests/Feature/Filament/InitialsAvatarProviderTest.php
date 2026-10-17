<?php

namespace Tests\Feature\Filament;

use App\Filament\Support\InitialsAvatarProvider;
use App\Models\AdminUser;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InitialsAvatarProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_avatar_is_an_inline_svg_of_the_initials(): void
    {
        $admin = AdminUser::factory()->create(['username' => 'site-admin']);

        $url = app(InitialsAvatarProvider::class)->get($admin);

        $this->assertStringStartsWith('data:image/svg+xml;utf8,', $url);
        $this->assertStringContainsString('>S<', rawurldecode($url));
    }

    public function test_two_words_give_two_initials_with_leading_punctuation_dropped(): void
    {
        $admin = AdminUser::factory()->create(['username' => '[bot] <x> & yo']);

        $this->assertStringContainsString('>BX<', rawurldecode(app(InitialsAvatarProvider::class)->get($admin)));
    }

    public function test_a_name_without_letters_still_draws_something(): void
    {
        $admin = AdminUser::factory()->create(['username' => '***']);

        $this->assertStringContainsString('>?<', rawurldecode(app(InitialsAvatarProvider::class)->get($admin)));
    }

    public function test_the_panel_draws_the_administrators_avatar_without_a_third_party(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(['username' => 'admin']), 'admin');

        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertDontSee('ui-avatars.com');
        $response->assertSee('data:image/svg+xml;utf8,', false);
    }
}
