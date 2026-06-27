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
     * Single column is the house default for admin forms: the custom settings Pages are already
     * single-column, so a Resource form that omits `->columns()` silently inherits Filament's
     * 2-column default and lays out ragged. `hasCustomColumns()` is exactly "did this form set a
     * column count, vs fall back to the default"; asserting explicitness (not exactly 1) keeps a
     * deliberate future multi-column root open while still catching the forgot-to-set-it case.
     */
    public function test_every_resource_form_sets_an_explicit_column_count(): void
    {
        $resources = Filament::getCurrentPanel()->getResources();

        $this->assertNotEmpty($resources, 'The admin panel registers resources to check.');

        foreach ($resources as $resource) {
            // A list-only moderation resource (e.g. diary monitoring) does not declare a form(),
            // so it inherits the base Resource::form() that returns the schema unchanged. The
            // single-column rule only constrains resources that actually have a form — skip the rest.
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
