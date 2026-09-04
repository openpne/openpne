<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\AdminUser;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class AdminFormColumnsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    /**
     * Explicitness is asserted rather than exactly one column, so a deliberate multi-column root stays
     * possible while an omitted `->columns()` (which inherits Filament's 2-column default) is caught.
     */
    public function test_every_resource_form_sets_an_explicit_column_count(): void
    {
        $resources = Filament::getCurrentPanel()->getResources();

        $this->assertNotEmpty($resources, 'The admin panel registers resources to check.');

        foreach ($resources as $resource) {
            // A list-only resource inherits the base Resource::form(), which returns the schema
            // unchanged, so it has no form to constrain.
            if ((new ReflectionMethod($resource, 'form'))->getDeclaringClass()->getName() === Resource::class) {
                continue;
            }

            $schema = $resource::form(Schema::make());

            $this->assertTrue(
                $schema->hasCustomColumns(),
                "{$resource} form must set an explicit ->columns(...) (single column is the default).",
            );
        }
    }
}
