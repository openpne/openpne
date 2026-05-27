<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelBootTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_page_renders(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_admin_panel_redirects_guests_to_login(): void
    {
        // An authenticated route rejects unauthenticated access. The panel root
        // sits behind the admin guard.
        $this->get('/admin')->assertRedirect('/admin/login');
    }
}
