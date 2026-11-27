<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_brand_name_carries_the_admin_suffix(): void
    {
        $this->assertSame(sns_name().' '.__('Admin panel'), Filament::getBrandName());
    }

    public function test_panel_browser_title_carries_the_admin_brand(): void
    {
        $html = $this->actingAsAdmin()->get('/admin')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<title>(.*?)<\/title>/s', $html, $title));
        $this->assertStringContainsString(sns_name(), $title[1]);
        $this->assertStringContainsString(__('Admin panel'), $title[1]);
    }
}
