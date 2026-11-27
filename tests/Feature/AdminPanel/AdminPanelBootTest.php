<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelBootTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_old_panel_login_address_leads_through_the_member_login_to_the_panel(): void
    {
        $this->get('/admin/login')->assertRedirect(route('login'));
        $this->actingAsAdmin()->get('/admin/login')->assertRedirect('/admin');
    }

    public function test_admin_panel_redirects_guests_to_the_member_login(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
        $this->assertSame(url('/admin'), session('url.intended'));
    }
}
