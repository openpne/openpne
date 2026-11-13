<?php

namespace Tests\Feature\File;

use App\Files\FileUploader;
use App\Files\ImageFastPath;
use App\Files\ImageTransform;
use App\Files\VariantUrlSigner;
use App\Listeners\Auth\RotateImageCookie;
use App\Models\AdminUser;
use App\Models\File;
use App\Models\Member;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Cookie as ResponseCookie;
use Tests\TestCase;

class VariantUrlSigningTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir().'/openpne-signing-'.bin2hex(random_bytes(6));
        mkdir($this->storage.'/framework', 0777, true);
        $this->app->useStoragePath($this->storage);

        Storage::fake('image_cache');
        config(['openpne.images.fast_path' => true, 'app.url' => 'http://localhost']);
        // The URLs a page draws take their host from here, whatever APP_URL the developer's .env holds.
        URL::forceRootUrl('http://localhost');

        // What a page does: the body is the URL it would put in an <img>.
        Route::middleware('web')->get('/_probe/url/{file:name}', fn (File $file): string => $file->thumbnailUrl(120, 120, square: true));
        Route::middleware('web')->get('/_probe/login/{id}/{file:name}', function (int $id, File $file): string {
            Auth::guard('member')->login(Member::query()->findOrFail($id));

            return $file->thumbnailUrl(120, 120, square: true);
        });
        Route::middleware('web')->get('/_probe/logout', function (): string {
            Auth::guard('member')->logout();

            return 'bye';
        });
        Route::middleware('web')->get('/_probe/logout-device', function (): string {
            Auth::guard('member')->logoutCurrentDevice();

            return 'bye';
        });
    }

    protected function tearDown(): void
    {
        @chmod($this->storage.'/framework', 0777);
        exec('rm -rf '.escapeshellarg($this->storage));

        parent::tearDown();
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function cookieSettings(): array
    {
        return [
            'plain' => [[], ImageFastPath::COOKIE],
            'secure' => [['session.secure' => true], ImageFastPath::SECURE_COOKIE],
            'secure with a session domain' => [['session.secure' => true, 'session.domain' => '.example.test'], ImageFastPath::SECURE_COOKIE],
            'a session domain alone' => [['session.domain' => '.example.test'], ImageFastPath::COOKIE],
        ];
    }

    /**
     * The cookie is taken as the browser would hold it, off the response, so an encrypted or misnamed
     * one fails here though both halves agree with themselves.
     *
     * @param  array<string, mixed>  $settings
     */
    #[DataProvider('cookieSettings')]
    public function test_the_url_and_cookie_a_page_sends_are_what_the_fast_path_accepts(array $settings, string $name): void
    {
        config($settings);
        $owner = Member::factory()->create();
        $file = $this->avatar($owner);

        $page = $this->actingAs($owner)->get("/_probe/url/{$file->name}")->assertOk();
        $url = $page->getContent();
        $cookie = $this->imageCookie($page, $name);

        $this->assertNull($cookie->getDomain());
        $this->assertSame('/', $cookie->getPath());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(isset($settings['session.secure']), $cookie->isSecure());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', (string) $cookie->getValue());

        // Through the application once, which draws the variant and gives the headers to match.
        $viaApp = $this->get($url)->assertOk();
        $answer = $this->fastPath($url, [$name => $cookie->getValue()]);

        $this->assertNotNull($answer);
        $this->assertSame($viaApp->getContent(), file_get_contents($answer['file']));
        $this->assertSame($viaApp->headers->get('Content-Type'), $answer['type']);
        $this->assertSame($viaApp->headers->get('ETag'), $answer['etag']);

        $this->assertNull($this->fastPath($url, [$name => str_repeat('0', 64)]));
        $this->assertNull($this->fastPath($url, []));
    }

    public function test_a_file_the_viewer_may_not_see_is_left_unsigned(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $file = $this->avatar($owner);
        $owner->blocksMade()->attach($viewer, ['created_at' => now()]);

        $page = $this->actingAs($viewer)->get("/_probe/url/{$file->name}")->assertOk();

        $this->assertStringNotContainsString('s=', $page->getContent());
        $this->assertNull($this->findImageCookie($page));
        $this->get($page->getContent())->assertNotFound();
    }

    public function test_a_guest_an_admin_page_and_a_console_get_no_signature(): void
    {
        $file = $this->avatar(Member::factory()->create());

        $this->assertStringNotContainsString('s=', $this->get("/_probe/url/{$file->name}")->assertOk()->getContent());
        $this->assertStringNotContainsString('s=', $file->thumbnailUrl(120, 120, square: true));

        Route::middleware('web')->get('/admin/_probe/url/{file:name}', fn (File $file): string => $file->thumbnailUrl(120, 120, square: true));
        // A request of its own, as each is outside a test: the signer reads the realm once.
        $this->app->forgetScopedInstances();
        $admin = $this->actingAs(Member::factory()->create())->get("/admin/_probe/url/{$file->name}");
        $this->assertStringNotContainsString('s=', (string) $admin->getContent());
    }

    public function test_the_policy_is_asked_once_for_a_file_however_many_urls_a_page_draws(): void
    {
        $owner = Member::factory()->create();
        $file = $this->avatar($owner);
        $asked = 0;
        Gate::after(function ($user, string $ability, ?bool $result, array $arguments) use (&$asked, $file): void {
            $asked += $ability === 'view' && ($arguments[0] ?? null) instanceof File && $arguments[0]->is($file) ? 1 : 0;
        });

        request()->setLaravelSession($this->app['session.store']);
        $this->actingAs($owner);
        $first = $file->thumbnailUrl(120, 120, square: true);
        $second = $file->thumbnailUrl(76, 76, square: true);

        $this->assertStringContainsString('s=', $first);
        $this->assertStringContainsString('s=', $second);
        $this->assertSame(1, $asked);
    }

    public function test_a_login_rotates_the_cookie_and_signs_the_same_response_with_the_new_value(): void
    {
        $owner = Member::factory()->create();
        $file = $this->avatar($owner);

        $before = $this->actingAs($owner)->get("/_probe/url/{$file->name}")->assertOk();
        $old = $this->imageCookie($before, ImageFastPath::COOKIE)->getValue();
        $this->get($before->getContent())->assertOk();
        $this->assertNotNull($this->fastPath($before->getContent(), [ImageFastPath::COOKIE => $old]));

        $after = $this->withUnencryptedCookie(ImageFastPath::COOKIE, (string) $old)->get("/_probe/login/{$owner->getKey()}/{$file->name}")->assertOk();
        $new = $this->imageCookie($after, ImageFastPath::COOKIE)->getValue();

        $this->assertNotSame($old, $new);
        $this->assertNotNull($this->fastPath($after->getContent(), [ImageFastPath::COOKIE => $new]));
        $this->assertNull($this->fastPath($after->getContent(), [ImageFastPath::COOKIE => $old]));
        $this->assertNull($this->fastPath($before->getContent(), [ImageFastPath::COOKIE => $new]));
    }

    public function test_a_logout_expires_the_cookie_under_both_names(): void
    {
        $response = $this->actingAs(Member::factory()->create())
            ->withUnencryptedCookies([ImageFastPath::COOKIE => str_repeat('a', 64), ImageFastPath::SECURE_COOKIE => str_repeat('b', 64)])
            ->get('/_probe/logout')
            ->assertOk();

        foreach ([ImageFastPath::COOKIE => false, ImageFastPath::SECURE_COOKIE => true] as $name => $secure) {
            $cookie = $this->imageCookie($response, $name);
            $this->assertTrue($cookie->isCleared(), $name);
            $this->assertSame($secure, $cookie->isSecure(), $name);
            $this->assertNull($cookie->getDomain(), $name);
        }
    }

    /** `auth.session` signs a device out this way after a password change elsewhere, and fires no Logout. */
    public function test_a_device_signed_out_without_a_logout_event_loses_the_cookie_too(): void
    {
        $response = $this->actingAs(Member::factory()->create())
            ->withUnencryptedCookies([ImageFastPath::COOKIE => str_repeat('a', 64)])
            ->get('/_probe/logout-device')
            ->assertOk();

        $this->assertTrue($this->imageCookie($response, ImageFastPath::COOKIE)->isCleared());
    }

    public function test_one_viewers_answer_is_never_reused_for_another_in_the_same_request(): void
    {
        $owner = Member::factory()->create();
        $blocked = Member::factory()->create();
        DB::table('member_blocks')->insert(['blocker_id' => $owner->getKey(), 'blocked_id' => $blocked->getKey(), 'created_at' => now()]);
        $file = $this->avatar($owner);
        request()->setLaravelSession($this->app['session.store']);
        $signer = app(VariantUrlSigner::class);

        Auth::guard('member')->setUser($owner);
        $this->assertNotNull($signer->sign($file, 'png/w120_h120_sq/'.$file->name, ImageTransform::version()));

        Auth::guard('member')->setUser($blocked);
        $this->assertNull($signer->sign($file, 'png/w120_h120_sq/'.$file->name, ImageTransform::version()));
    }

    /** A value kept across a login while the file was absent would verify the previous member's URLs once it is back. */
    public function test_a_login_rotates_the_cookie_while_the_fast_path_is_off(): void
    {
        $first = Member::factory()->create();
        $second = Member::factory()->create();
        $file = $this->avatar($first);

        $before = $this->actingAs($first)->get("/_probe/url/{$file->name}")->assertOk();
        $old = (string) $this->imageCookie($before, ImageFastPath::COOKIE)->getValue();
        $this->get($before->getContent())->assertOk();

        config(['openpne.images.fast_path' => false]);
        $this->app->forgetScopedInstances();
        Cookie::flushQueuedCookies();
        $login = $this->withUnencryptedCookie(ImageFastPath::COOKIE, $old)->get("/_probe/login/{$second->getKey()}/{$file->name}")->assertOk();
        $new = (string) $this->imageCookie($login, ImageFastPath::COOKIE)->getValue();
        $this->assertNotSame($old, $new);

        config(['openpne.images.fast_path' => true]);
        $this->assertTrue(ImageFastPath::sync());
        $this->assertNull($this->fastPath($before->getContent(), [ImageFastPath::COOKIE => $new]));
    }

    /** Without the `__Host-` prefix a sibling host can set this cookie, so the value signed for is never the one a request brought. */
    public function test_a_cookie_the_request_brought_is_not_what_a_url_is_signed_for(): void
    {
        $owner = Member::factory()->create();
        $file = $this->avatar($owner);
        $planted = str_repeat('e', 64);

        $page = $this->actingAs($owner)->withUnencryptedCookie(ImageFastPath::COOKIE, $planted)->get("/_probe/url/{$file->name}")->assertOk();
        $this->get($page->getContent())->assertOk();

        $issued = (string) $this->imageCookie($page, ImageFastPath::COOKIE)->getValue();
        $this->assertNotSame($planted, $issued);
        $this->assertNull($this->fastPath($page->getContent(), [ImageFastPath::COOKIE => $planted]));
        $this->assertNotNull($this->fastPath($page->getContent(), [ImageFastPath::COOKIE => $issued]));
    }

    public function test_an_admin_signing_in_leaves_the_members_cookie_alone(): void
    {
        request()->setLaravelSession($this->app['session.store']);
        app(RotateImageCookie::class)->handleLogin(new Login('admin', AdminUser::factory()->create(), false));

        $this->assertFalse(Cookie::hasQueued(ImageFastPath::COOKIE));

        app(RotateImageCookie::class)->handleLogin(new Login('member', Member::factory()->create(), false));

        $this->assertTrue(Cookie::hasQueued(ImageFastPath::COOKIE));
    }

    public function test_the_cookie_lives_as_long_as_the_session_cookie_does(): void
    {
        $owner = Member::factory()->create();
        $file = $this->avatar($owner);
        $this->travelTo(now()->startOfSecond());

        $this->assertSame(
            now()->addMinutes(90)->getTimestamp(),
            $this->expiryUnder(['session.driver' => 'array', 'session.lifetime' => 90], $owner, $file),
        );
        $this->assertSame(0, $this->expiryUnder(['session.driver' => 'array', 'session.expire_on_close' => true], $owner, $file));
        $this->assertSame(
            now()->addMinutes(43200)->getTimestamp(),
            $this->expiryUnder(['session.driver' => 'database', 'session.expire_on_close' => false, 'session.member_lifetime' => 43200], $owner, $file),
        );
        $this->assertSame(0, $this->expiryUnder(['session.driver' => 'database', 'session.expire_on_close' => true], $owner, $file));
    }

    /** @param  array<string, mixed>  $settings */
    private function expiryUnder(array $settings, Member $owner, File $file): int
    {
        config($settings);
        // A request of its own: the signer and the cookie jar otherwise carry over between test requests.
        $this->app->forgetScopedInstances();
        Cookie::flushQueuedCookies();

        $page = $this->actingAs($owner)->get("/_probe/url/{$file->name}")->assertOk();

        $expires = $this->imageCookie($page, ImageFastPath::COOKIE)->getExpiresTime();
        $this->assertSame($this->imageCookie($page, (string) config('session.cookie'))->getExpiresTime(), $expires, json_encode($settings));

        return $expires;
    }

    public function test_the_published_file_is_private_to_its_owner_and_leaves_no_temp_behind(): void
    {
        $this->assertTrue(ImageFastPath::sync());

        $path = ImageFastPath::path();
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame([basename($path)], $this->published());

        $config = json_decode((string) file_get_contents($path), true);
        $this->assertSame(['schema', 'key', 'host', 'root', 'encoder', 'version', 'cookie'], array_keys($config));
        $this->assertSame('localhost', $config['host']);
        $this->assertStringNotContainsString((string) config('app.key'), (string) file_get_contents($path));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function inapplicableSettings(): array
    {
        return [
            'switched off' => [['openpne.images.fast_path' => false]],
            'a cache disk that is not local' => [['filesystems.disks.image_cache.driver' => 's3']],
            'no host in APP_URL' => [['app.url' => '']],
        ];
    }

    /** @param  array<string, mixed>  $settings */
    #[DataProvider('inapplicableSettings')]
    public function test_where_it_cannot_apply_the_file_is_removed_and_nothing_is_signed(array $settings): void
    {
        $owner = Member::factory()->create();
        $file = $this->avatar($owner);
        $this->assertTrue(ImageFastPath::sync());

        config($settings);

        $this->assertFalse(ImageFastPath::sync());
        $this->assertFileDoesNotExist(ImageFastPath::path());
        $this->actingAs($owner);
        $this->assertStringNotContainsString('s=', $file->thumbnailUrl(120, 120, square: true));
    }

    public function test_a_file_that_cannot_be_written_means_nothing_is_signed(): void
    {
        $owner = Member::factory()->create();
        $file = $this->avatar($owner);
        chmod($this->storage.'/framework', 0500);

        $this->assertFalse(ImageFastPath::sync());
        $this->actingAs($owner);
        $this->assertStringNotContainsString('s=', $file->thumbnailUrl(120, 120, square: true));
        $this->assertSame([], $this->published());
    }

    public function test_a_published_file_others_can_read_is_written_again(): void
    {
        $this->assertTrue(ImageFastPath::sync());
        chmod(ImageFastPath::path(), 0644);
        clearstatcache();

        $this->assertTrue(ImageFastPath::sync());
        clearstatcache();
        $this->assertSame(0600, fileperms(ImageFastPath::path()) & 0777);
    }

    public function test_a_file_that_cannot_be_removed_is_a_failure_and_not_an_off_switch(): void
    {
        $this->assertTrue(ImageFastPath::sync());
        config(['openpne.images.fast_path' => false]);
        chmod($this->storage.'/framework', 0500);

        $this->assertFalse(ImageFastPath::sync());
        $this->assertFileExists(ImageFastPath::path());
        $this->assertTrue(ImageFastPath::lingers());
        $this->artisan('openpne:image-fast-path')->assertFailed();

        chmod($this->storage.'/framework', 0700);
        $this->artisan('openpne:image-fast-path')->assertSuccessful();
        $this->assertFalse(ImageFastPath::lingers());
    }

    public function test_a_file_another_release_wrote_never_answers_for_this_ones_urls(): void
    {
        $owner = Member::factory()->create();
        $file = $this->avatar($owner);

        $page = $this->actingAs($owner)->get("/_probe/url/{$file->name}")->assertOk();
        $url = $page->getContent();
        $cookies = [ImageFastPath::COOKIE => $this->imageCookie($page, ImageFastPath::COOKIE)->getValue()];
        $this->get($url)->assertOk();
        $this->assertNotNull($this->fastPath($url, $cookies));

        // A worker of a release with another encoder publishes its own state over this one's.
        $ours = (string) file_get_contents(ImageFastPath::path());
        config(['openpne.images.quality' => config('openpne.images.quality') - 1]);
        $this->assertTrue(ImageFastPath::sync());
        $this->assertNotSame($ours, file_get_contents(ImageFastPath::path()));

        $this->assertNull($this->fastPath($url, $cookies));
    }

    /** The suite runs as a console, where the provider leaves the file alone, so this boots one application as a web one. */
    public function test_every_web_boot_brings_the_file_in_line_without_a_picture_being_drawn(): void
    {
        $this->assertTrue(ImageFastPath::sync());
        $path = ImageFastPath::path();
        $before = [$_ENV['APP_RUNNING_IN_CONSOLE'] ?? null, $_ENV['LARAVEL_STORAGE_PATH'] ?? null];
        $_ENV['APP_RUNNING_IN_CONSOLE'] = 'false';
        $_ENV['LARAVEL_STORAGE_PATH'] = $this->storage;
        mkdir($this->storage.'/framework/views');

        try {
            // phpunit.xml switches the fast path off, which is what the fresh application reads.
            $this->refreshApplication();
            $this->assertSame($path, ImageFastPath::path());
            $this->assertFileDoesNotExist($path);
        } finally {
            foreach (['APP_RUNNING_IN_CONSOLE', 'LARAVEL_STORAGE_PATH'] as $i => $name) {
                if ($before[$i] === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $before[$i];
                }
            }
        }
    }

    public function test_the_command_reports_what_it_left_on_disk(): void
    {
        $this->artisan('openpne:image-fast-path')->assertSuccessful();
        $this->assertFileExists(ImageFastPath::path());

        config(['openpne.images.fast_path' => false]);
        $this->artisan('openpne:image-fast-path')->assertSuccessful();
        $this->assertFileDoesNotExist(ImageFastPath::path());

        config(['openpne.images.fast_path' => true]);
        chmod($this->storage.'/framework', 0500);
        $this->artisan('openpne:image-fast-path')->assertFailed();
    }

    /**
     * @param  array<string, mixed>  $cookies
     * @return array{file: string, type: string, etag: string}|null
     */
    private function fastPath(string $url, array $cookies): ?array
    {
        require_once base_path('bootstrap/image-fast-path.php');

        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);

        return openpne_image_fast_path(
            ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => $parts['host'], 'REQUEST_URI' => $parts['path'].'?'.($parts['query'] ?? '')],
            $query,
            $cookies,
            storage_path(),
        );
    }

    /** @return list<string> the fast path file and any temp left beside it */
    private function published(): array
    {
        return array_values(array_filter(scandir($this->storage.'/framework'), fn (string $entry): bool => str_starts_with($entry, 'image-fast-path')));
    }

    private function imageCookie(TestResponse $response, string $name): ResponseCookie
    {
        $cookie = $this->findImageCookie($response, $name);
        $this->assertNotNull($cookie, "The response sets no [{$name}] cookie.");

        return $cookie;
    }

    private function findImageCookie(TestResponse $response, ?string $name = null): ?ResponseCookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name || ($name === null && in_array($cookie->getName(), [ImageFastPath::COOKIE, ImageFastPath::SECURE_COOKIE], true))) {
                return $cookie;
            }
        }

        return null;
    }

    private function avatar(Member $owner): File
    {
        return app(FileUploader::class)->store(UploadedFile::fake()->image('a.png', 240, 120), 'member', (int) $owner->getKey());
    }
}
