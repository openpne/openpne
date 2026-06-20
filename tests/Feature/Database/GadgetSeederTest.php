<?php

namespace Tests\Feature\Database;

use App\Gadgets\GadgetKindRegistry;
use App\Models\Gadget;
use Database\Seeders\GadgetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GadgetSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_openpne3_default_pc_set(): void
    {
        $this->seed(GadgetSeeder::class);

        $this->assertDatabaseHas('gadgets', ['context' => 'home', 'zone' => 'top', 'name' => 'informationBox']);
        $this->assertDatabaseHas('gadgets', ['context' => 'profile', 'zone' => 'sideMenu', 'name' => 'profileListBox']);
        $this->assertDatabaseHas('gadgets', ['context' => 'login', 'zone' => 'contents', 'name' => 'loginForm']);
        $this->assertDatabaseHas('gadgets', ['context' => 'sidebanner', 'zone' => 'contents', 'name' => 'languageSelecterBox']);
    }

    public function test_every_seeded_gadget_is_a_registered_kind_for_its_context(): void
    {
        $this->seed(GadgetSeeder::class);

        foreach (Gadget::all() as $gadget) {
            $kind = GadgetKindRegistry::find($gadget->name);
            $this->assertNotNull($kind, "{$gadget->name} is registered");
            $this->assertContains($gadget->context, $kind->contexts(), "{$gadget->name} supports {$gadget->context}");
        }
    }
}
