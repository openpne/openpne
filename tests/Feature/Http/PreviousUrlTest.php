<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Files\FileStorage;
use App\Files\FileUploader;
use App\Models\BannerImage;
use App\Models\File;
use App\Models\Member;
use App\Models\RegistrationToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What the session records as the previous URL — the target of every redirect()->back(), so of every
 * validation error and failed login. A page pulls in cookie-bearing subresources of its own, so
 * recording each routed GET would leave an image or a 404 as the back target and lose the error the
 * form was meant to show. See App\Http\Middleware\StartSession.
 *
 * These cases must not use $this->from(): setting the previous URL by hand is what let the flaw sit
 * unnoticed in the suites that cover those forms.
 */
class PreviousUrlTest extends TestCase
{
    use RefreshDatabase;

    /** The delivery URL of an admin-uploaded public asset — what the brand mark on an auth screen is. */
    private function brandMarkUrl(): string
    {
        $file = File::factory()->create([
            'type' => 'image/png',
            'explicit_visibility' => File::VISIBILITY_PUBLIC,
            'byte_size' => 7,
        ]);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'PNGDATA');
        rewind($stream);
        app(FileStorage::class)->writeStream($file, $stream);
        fclose($stream);

        return "/file/public/{$file->name}";
    }

    private function bannerImageUrl(): string
    {
        $banner = BannerImage::factory()->create();
        $file = File::factory()->create([
            'type' => 'image/png',
            'related_entity_type' => 'bannerImage',
            'related_entity_id' => $banner->getKey(),
            'byte_size' => 7,
        ]);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'PNGDATA');
        rewind($stream);
        app(FileStorage::class)->writeStream($file, $stream);
        fclose($stream);

        return route('banner.image', ['file' => $file->name]);
    }

    private function previousUrl(): ?string
    {
        return session()->previousUrl();
    }

    public function test_a_navigation_is_recorded(): void
    {
        $this->withHeader('Sec-Fetch-Dest', 'document')->get('/login')->assertOk();

        $this->assertSame(url('/login'), $this->previousUrl());
    }

    public function test_a_client_without_fetch_metadata_records_a_navigation(): void
    {
        $this->get('/login')->assertOk();

        $this->assertSame(url('/login'), $this->previousUrl());
    }

    public function test_a_client_side_visit_is_recorded(): void
    {
        // Inertia's client sends X-Requested-With alongside X-Inertia, so the framework's XHR
        // exclusion covers the very case this must record. Livewire's navigate fetch does not.
        $visits = [
            'inertia' => ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'],
            'livewire' => ['X-Livewire-Navigate' => ''],
        ];

        foreach ($visits as $client => $headers) {
            $this->withHeader('Sec-Fetch-Dest', 'document')->get('/login');

            $this->withHeaders($headers + ['Sec-Fetch-Dest' => 'empty'])->get('/forgot-password');

            $this->assertSame(url('/forgot-password'), $this->previousUrl(), $client);
        }
    }

    public function test_a_prefetched_visit_is_not_recorded(): void
    {
        $this->withHeader('Sec-Fetch-Dest', 'document')->get('/login')->assertOk();

        // A page the browser fetched in case the visitor goes there is not a page they are on.
        $this->withHeaders(['Sec-Fetch-Dest' => 'document', 'Sec-Purpose' => 'prefetch'])->get('/forgot-password');

        $this->assertSame(url('/login'), $this->previousUrl());
    }

    public function test_a_validation_error_returns_to_the_page_a_client_side_visit_reached(): void
    {
        $this->withHeader('Sec-Fetch-Dest', 'document')->get('/login')->assertOk();
        $this->withHeaders(['Sec-Fetch-Dest' => 'empty', 'X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
            ->get('/forgot-password');

        $this->post('/forgot-password', ['email' => ''])
            ->assertRedirect('/forgot-password')
            ->assertSessionHasErrors('email');
    }

    public function test_a_subresource_does_not_replace_the_previous_url(): void
    {
        $image = $this->brandMarkUrl();
        $this->withHeader('Sec-Fetch-Dest', 'document')->get('/login')->assertOk();

        foreach (['image', 'manifest', 'style', 'script', 'font'] as $dest) {
            $this->withHeader('Sec-Fetch-Dest', $dest)->get($image)->assertOk();

            $this->assertSame(url('/login'), $this->previousUrl(), $dest);
        }
    }

    public function test_a_route_that_never_answers_with_a_page_is_not_recorded_whatever_its_headers(): void
    {
        Storage::fake('image_cache');
        $owner = Member::factory()->create();
        $avatar = app(FileUploader::class)->store(UploadedFile::fake()->image('a.png', 40, 40), 'member', (int) $owner->getKey());

        $urls = [
            'file.public' => $this->brandMarkUrl(),
            'image.show' => $avatar->thumbnailUrl(120, 120, square: true),
            'banner.image' => $this->bannerImageUrl(),
            'webmanifest' => route('webmanifest'),
            'design.customizing_css' => route('design.customizing_css'),
            // A stale token answers 404 — which the framework would record all the same; the route
            // decides, not the status.
            'app_icon' => route('app_icon', ['token' => 'stale', 'size' => 180]),
        ];

        foreach ($urls as $name => $url) {
            $this->withHeader('Sec-Fetch-Dest', 'document')->get('/login')->assertOk();

            // No Fetch Metadata — the framework's rule would record this plain GET. withHeader()
            // persists for the test, so the header has to be taken off again.
            $this->withoutHeader('Sec-Fetch-Dest')->get($url);
            $this->assertSame(url('/login'), $this->previousUrl(), "{$name} without Fetch Metadata");

            // And the header a browser sends when it shows the resource in a tab of its own: bytes
            // are never a form to return to.
            $this->withHeader('Sec-Fetch-Dest', 'document')->get($url);
            $this->assertSame(url('/login'), $this->previousUrl(), "{$name} as a document");
        }
    }

    public function test_a_background_fetch_does_not_replace_the_previous_url(): void
    {
        $image = $this->brandMarkUrl();
        $this->withHeader('Sec-Fetch-Dest', 'document')->get('/login')->assertOk();

        // Dest 'empty' is both a client-side page visit and an ordinary fetch(); only the visit's
        // own header tells them apart.
        $this->withHeader('Sec-Fetch-Dest', 'empty')->get($image)->assertOk();

        $this->assertSame(url('/login'), $this->previousUrl());
    }

    public function test_an_unmatched_url_is_never_recorded(): void
    {
        $this->withHeader('Sec-Fetch-Dest', 'document')->get('/login')->assertOk();

        // No Fetch Metadata at all — a probe or a tool, the case headers cannot rule out (Chrome
        // DevTools asks every page for /.well-known/appspecific/com.chrome.devtools.json).
        // withHeader() persists for the test, so the header has to be taken off again.
        $this->withoutHeader('Sec-Fetch-Dest')->get('/.well-known/appspecific/com.chrome.devtools.json')->assertNotFound();

        $this->assertSame(url('/login'), $this->previousUrl());
    }

    public function test_a_failed_login_returns_to_the_form_the_brand_mark_loaded_on(): void
    {
        $member = Member::factory()->create();
        $image = $this->brandMarkUrl();

        $this->withHeader('Sec-Fetch-Dest', 'document')->get('/login')->assertOk();
        $this->withHeader('Sec-Fetch-Dest', 'image')->get($image)->assertOk();

        $this->post('/login', ['email' => $member->email, 'password' => 'wrong-password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
    }

    public function test_a_registration_validation_error_returns_to_the_form(): void
    {
        $raw = Str::random(40);
        RegistrationToken::create(['email' => 'newcomer@example.com', 'token' => hash('sha256', $raw), 'created_at' => now()]);
        $image = $this->brandMarkUrl();

        $this->withHeader('Sec-Fetch-Dest', 'document')->get("/register/{$raw}")->assertOk();
        $this->withHeader('Sec-Fetch-Dest', 'image')->get($image)->assertOk();

        $this->post("/register/{$raw}", ['name' => 'Newcomer', 'password' => 'sufficiently-long-pw', 'password_confirmation' => 'different'])
            ->assertRedirect("/register/{$raw}")
            ->assertSessionHasErrors('password');
    }
}
