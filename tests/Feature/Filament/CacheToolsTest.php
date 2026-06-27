<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\CacheTools;
use App\Models\AdminUser;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class CacheToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_page_renders(): void
    {
        Livewire::test(CacheTools::class)->assertSuccessful();
    }

    public function test_clear_action_forgets_managed_caches_and_notifies(): void
    {
        // Warm two of the managed caches under their real keys (sns_settings core + a term locale).
        Cache::put('sns_settings', ['sentinel' => true], 60);
        Cache::put('terms.ja', ['sentinel' => true], 60);

        Livewire::test(CacheTools::class)
            ->callAction('clear')
            ->assertNotified(__('Caches cleared'));

        $this->assertFalse(Cache::has('sns_settings'));
        $this->assertFalse(Cache::has('terms.ja'));
    }
}
