<?php

namespace Tests\Feature\Http;

use App\Files\FileStorage;
use App\Models\Diary;
use App\Models\File;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pins that the guest-reachable routes listed below still carry `auth.session`: dropping `auth` must
 * not also drop it, since AuthenticateSession is what ends a session whose password hash is stale,
 * and every gate on these pages reads the viewer it would otherwise leave in place.
 */
class PublicRouteBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function publicMemberRoutes(): array
    {
        return [
            'home' => ['home'],
            'profile' => ['member.profile.show'],
            'profile raw alias' => ['member.profile.raw_compat'],
            'file bytes' => ['file.show'],
            'thumbnail' => ['image.show'],
            'diary top' => ['diary.index_compat'],
            'diary feed' => ['diary.list'],
            'diary search' => ['diary.search'],
            'diary entry' => ['diary.show'],
            'diary archive' => ['diary.list_member'],
            'diary month archive' => ['diary.list_member.archive'],
        ];
    }

    #[DataProvider('publicMemberRoutes')]
    public function test_a_guest_reachable_route_still_carries_auth_session(string $name): void
    {
        $route = Route::getRoutes()->getByName($name);
        $this->assertInstanceOf(RoutingRoute::class, $route, "route [{$name}] is not registered");

        $middleware = $route->gatherMiddleware();
        $this->assertNotContains('auth', $middleware, "route [{$name}] is no longer guest-reachable");
        $this->assertContains('auth.session', $middleware,
            "route [{$name}] is guest-reachable but lost [auth.session]: a stale session would keep a viewer here.");
    }

    public function test_a_stale_session_is_ended_on_the_home_page(): void
    {
        // Classic serves the member's gadget home at /; a Modern surface renders the day's issue
        // there, and covers this same staleness in HomeIssueRoutesTest.
        config(['openpne.surface_mode' => 'classic_default']);
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/')->assertOk();
        // Another device changes the password; this session's stored hash is now stale.
        $member->forceFill(['password' => Hash::make('changed-elsewhere')])->save();
        $this->get('/')->assertRedirect('/login');
    }

    public function test_a_stale_session_reads_no_more_than_a_guest_on_a_public_page(): void
    {
        $author = Member::factory()->create();
        $friend = Member::factory()->create();
        $members = Diary::factory()->create([
            'member_id' => $author->getKey(),
            'title' => 'Members entry',
            'visibility' => Visibility::Members,
        ]);

        $this->actingAs($friend)->get("/diary/{$members->getKey()}")->assertOk();

        // Another device changes the password; this session's stored hash is now stale.
        $friend->forceFill(['password' => Hash::make('changed-elsewhere')])->save();

        $this->get("/diary/{$members->getKey()}")->assertRedirect('/login');
    }

    public function test_a_stale_session_cannot_keep_fetching_an_owner_gated_file(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]);
        $file = File::factory()->create([
            'type' => 'image/png',
            'related_entity_type' => 'diary',
            'related_entity_id' => $diary->getKey(),
            'byte_size' => strlen('PNGDATA'),
        ]);
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, 'PNGDATA');
        rewind($stream);
        app(FileStorage::class)->writeStream($file, $stream);
        fclose($stream);

        $this->actingAs($viewer)->get($file->url())->assertOk();

        $viewer->forceFill(['password' => Hash::make('changed-elsewhere')])->save();

        $this->get($file->url())->assertRedirect('/login');
    }
}
