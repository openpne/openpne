<?php

declare(strict_types=1);

namespace App\SiteCheck;

use App\Files\AttachmentLimit;
use App\Files\ImageSourceLimit;
use App\Files\PostImages;
use App\Files\UploadLimit;
use Illuminate\Contracts\Filesystem\LockTimeoutException;
use Illuminate\Filesystem\LockableFile;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/** The site as the web serves it, judged from files it writes into the site for a few seconds (docs/internals/runtime.md, "Site check"). */
final class WebCheck
{
    public const HEADER = 'X-OpenPNE-Check';

    public const LEFTOVER_SECONDS = 600;

    public const NAME_PATTERN = '/^openpne-check-[0-9a-f]{32}\.(?:txt|php|owner)$/';

    public const LOCK_FILE = 'openpne-check.lock';

    private ?LockableFile $lock = null;

    /** @var list<string> */
    private array $written = [];

    /** @var array<string, string> */
    private array $tokens = [];

    public function __construct(
        private readonly SiteLayout $layout,
        private readonly SiteFetcher $fetcher,
    ) {}

    /** @return list<Finding> */
    public function run(?string $parentUrl = null): array
    {
        $url = rtrim((string) config('app.url'), '/');
        if (! InstalledCheck::usableAppUrl($url)) {
            return [Finding::unknown('web', 'web_no_app_url')];
        }
        if ($parentUrl !== null && ! in_array(parse_url($parentUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return [Finding::unknown('web', 'web_parent_url_unusable')];
        }

        $lockFile = $this->layout->storage.'/framework/'.self::LOCK_FILE;
        try {
            $lock = new LockableFile($lockFile, 'c');
        } catch (Throwable) {
            return [Finding::unknown('web', 'web_cannot_write', ['path' => $lockFile])];
        }
        try {
            $lock->getExclusiveLock();
        } catch (LockTimeoutException) {
            $lock->close();

            return [Finding::unknown('web', 'web_busy')];
        }
        $this->lock = $lock;

        try {
            $this->removeLeftovers();

            return $this->probe($url, $parentUrl === null ? null : rtrim($parentUrl, '/'));
        } finally {
            $this->cleanUp();
        }
    }

    /** Safe to call again, and from a signal handler at any point of a run or of this call. */
    public function cleanUp(): void
    {
        foreach ($this->written as $path) {
            @unlink($path);
        }
        $this->written = [];
        $this->tokens = [];
        $lock = $this->lock;
        $this->lock = null;
        $lock?->releaseLock();
        $lock?->close();
    }

    public static function probeSource(string $token, string $ownerFile): string
    {
        return strtr(File::get(__DIR__.'/web-probe.stub'), [
            "'{{token}}'" => var_export($token, true),
            "'{{ownerFile}}'" => var_export($ownerFile, true),
        ]);
    }

    /** @return list<Finding> */
    private function probe(string $url, ?string $parentUrl): array
    {
        $name = 'openpne-check-'.bin2hex(random_bytes(16));
        $public = $this->write($this->layout->public, $name.'.txt');
        if ($public === null) {
            return [Finding::unknown('web', 'web_cannot_write', ['path' => $this->layout->public])];
        }
        $top = $this->write($this->layout->base, $name.'.txt');
        $storage = $this->layout->storageInTree() ? $this->write($this->layout->storage, $name.'.txt') : null;

        $page = $this->fetcher->get("{$url}/{$name}.txt");
        $root = $this->root($page, $url, $name, $public, $top);

        $served = [];
        if ($top !== null && $page->answered($top)) {
            $served[] = $this->layout->base;
        }
        if ($storage !== null && $this->fetcher->get("{$url}/storage/{$name}.txt")->answered($storage)) {
            $served[] = $this->layout->storage;
        }
        $findings = [
            $root,
            match (true) {
                $served !== [] => Finding::fail('web.tree', 'web_tree_served', ['list' => implode(', ', $served), 'public' => $this->layout->public]),
                // Only a fetch that reached public/ shows where the rest of the site sits from APP_URL.
                $root->verdict === Verdict::Ok => Finding::ok('web.tree', 'web_tree'),
                default => Finding::unknown('web.tree', 'web_tree_unchecked'),
            },
        ];

        if ($root->verdict === Verdict::Ok) {
            array_push($findings, $this->up($url), ...$this->php($url, $name));
        } else {
            $findings[] = Finding::unknown('web.php', 'web_php_unchecked');
        }

        if ($parentUrl !== null) {
            $findings[] = $this->parent($parentUrl, $name);
        }

        return $findings;
    }

    private function root(FetchedPage $page, string $url, string $name, string $public, ?string $top): Finding
    {
        $replace = ['url' => $url, 'public' => $this->layout->public, 'base' => $this->layout->base];

        if ($page->answered($public)) {
            return Finding::ok('web.root', 'web_root', $replace);
        }
        if ($top !== null && $page->answered($top)) {
            return Finding::fail('web.root', 'web_root_release', $replace);
        }
        if ($page->status === null) {
            return Finding::fail('web.root', 'web_unreachable', [...$replace, 'reason' => $page->reason()]);
        }
        if ($page->status >= 300 && $page->status < 400) {
            return Finding::fail('web.root', 'web_redirect', [...$replace, 'location' => (string) $page->location]);
        }
        // With the release itself served and its 403 working, public/ answers one level down.
        if ($this->fetcher->get("{$url}/public/{$name}.txt")->answered($public)) {
            return Finding::fail('web.root', 'web_root_release', $replace);
        }

        return match ($page->status) {
            403 => Finding::fail('web.root', 'web_root_403', $replace),
            404 => Finding::fail('web.root', 'web_root_404', $replace),
            500 => Finding::fail('web.root', 'web_root_500', $replace),
            200 => Finding::fail('web.root', 'web_root_other', $replace),
            default => Finding::fail('web.root', 'web_root_status', [...$replace, 'status' => $page->status]),
        };
    }

    private function up(string $url): Finding
    {
        $page = $this->fetcher->get("{$url}/up");

        return match (true) {
            str_contains($page->body, '<?php') => Finding::fail('web.up', 'web_up_source'),
            $page->status === 200 => Finding::ok('web.up', 'web_up'),
            // The words of vendor/composer/platform_check.php, which runs before the app can answer.
            str_contains($page->body, 'Composer detected issues in your platform') => Finding::fail('web.up', 'web_up_platform'),
            $page->status === null => Finding::fail('web.up', 'web_up_unreachable', ['reason' => $page->reason()]),
            default => Finding::fail('web.up', 'web_up_status', ['status' => $page->status]),
        };
    }

    /** @return list<Finding> */
    private function php(string $url, string $name): array
    {
        $token = bin2hex(random_bytes(16));
        $owner = $this->layout->storage.'/framework/'.$name.'.owner';
        $this->written[] = $owner;
        if ($this->write($this->layout->public, $name.'.php', self::probeSource($token, $owner)) === null) {
            return [Finding::unknown('web.php', 'web_cannot_write', ['path' => $this->layout->public])];
        }

        $page = $this->fetcher->get("{$url}/{$name}.php", [self::HEADER => $token]);
        $data = $page->status === 200 ? json_decode($page->body, true) : null;
        if (! is_array($data) || ($data['token'] ?? null) !== $token) {
            return [str_contains($page->body, '<?php')
                ? Finding::fail('web.php', 'web_php_not_run')
                : Finding::fail('web.php', 'web_php_failed', ['status' => $page->status ?? $page->reason()])];
        }

        $version = (string) ($data['version'] ?? '');
        $sapi = (string) ($data['sapi'] ?? '');
        $ini = is_array($data['ini'] ?? null) ? $data['ini'] : [];

        return array_values(array_filter([
            version_compare($version, '8.3.0', '<')
                ? Finding::fail('web.php', 'web_php_old', ['version' => $version, 'cli' => PHP_VERSION])
                : Finding::ok('web.php', 'web_php', ['version' => $version, 'sapi' => $sapi, 'cli' => PHP_VERSION]),
            $this->extensions(is_array($data['extensions'] ?? null) ? $data['extensions'] : []),
            $this->memory((string) ($ini['memory_limit'] ?? '')),
            $this->upload((string) ($ini['upload_max_filesize'] ?? ''), (string) ($ini['post_max_size'] ?? '')),
            str_starts_with($sapi, 'cgi') && ($ini['opcache.file_cache'] ?? '') === ''
                ? Finding::warn('web.opcache', 'web_cgi_opcache', ['sapi' => $sapi])
                : null,
            $this->runner((bool) ($data['wrote'] ?? false), $owner, $this->layout->public.'/'.$name.'.php'),
            $this->proxy(
                array_map('strval', is_array($data['proxy_headers'] ?? null) ? $data['proxy_headers'] : []),
                is_string($data['peer'] ?? null) ? $data['peer'] : null,
            ),
        ]));
    }

    /** @param array<mixed> $loaded */
    private function extensions(array $loaded): Finding
    {
        $missing = BeforeInstallCheck::missingExtensions(array_map('strval', $loaded));
        $driver = (string) config('database.connections.'.config('database.default').'.driver');
        $pdo = $driver === 'sqlite' ? 'pdo_sqlite' : 'pdo_mysql';
        if (! in_array($pdo, array_map('strtolower', array_map('strval', $loaded)), true)) {
            $missing[] = $pdo;
        }

        return $missing === []
            ? Finding::ok('web.extensions', 'web_extensions')
            : Finding::fail('web.extensions', 'web_extensions_missing', ['list' => implode(', ', $missing)]);
    }

    /** Under GD a decode runs in the worker, so the memory_limit is judged against the pixel limit it must hold. */
    private function memory(string $value): Finding
    {
        $bytes = BeforeInstallCheck::bytes($value);

        if (config('openpne.images.processor') !== 'gd' || $bytes === null || $bytes < 0) {
            return BeforeInstallCheck::enoughMemory($value)
                ? Finding::ok('web.memory', 'web_memory', ['value' => $value])
                : Finding::warn('web.memory', 'web_memory_low', ['value' => $value]);
        }

        $cap = ImageSourceLimit::pixels();
        $fits = ImageSourceLimit::pixelsFor($bytes);
        $replace = ['value' => $value, 'cap' => number_format($cap), 'fits' => number_format(max($fits, 0)), 'needed' => ImageSourceLimit::megabytesFor($cap).'M'];

        return match (true) {
            $fits <= 0 => Finding::warn('web.memory', 'web_memory_images_none', $replace),
            $fits < $cap => Finding::warn('web.memory', 'web_memory_images_low', $replace),
            default => Finding::ok('web.memory', 'web_memory_images', $replace),
        };
    }

    private function upload(string $upload, string $post): Finding
    {
        $cap = max(UploadLimit::bytes(), AttachmentLimit::bytes());
        $uploadBytes = BeforeInstallCheck::bytes($upload);
        $postBytes = BeforeInstallCheck::bytes($post);
        // PHP reads 0 as no limit for both; a compose form carries MAX_IMAGES files at the cap.
        $postFloor = PostImages::MAX_IMAGES * $cap;
        $enough = $uploadBytes !== null && ($uploadBytes === 0 || $uploadBytes >= $cap)
            && $postBytes !== null && ($postBytes === 0 || $postBytes > $postFloor);
        $replace = [
            'upload' => $upload,
            'post' => $post,
            'need_upload' => (int) ceil($cap / 1048576).'M',
            'need_post' => intdiv($postFloor, 1048576) + 1 .'M',
        ];

        return $enough
            ? Finding::ok('web.upload', 'web_upload', $replace)
            : Finding::warn('web.upload', 'web_upload_low', $replace);
    }

    private function runner(bool $wrote, string $owner, string $probe): Finding
    {
        clearstatcache();
        if (! $wrote) {
            return Finding::fail('web.runner', 'web_runner_unwritable', ['path' => dirname($owner)]);
        }
        $webOwner = @fileowner($owner);
        $ours = @fileowner($probe);
        if ($webOwner === false || $ours === false) {
            return Finding::unknown('web.runner', 'web_runner_unknown');
        }

        return $webOwner === $ours
            ? Finding::ok('web.runner', 'web_runner')
            : Finding::fail('web.runner', 'web_runner_other');
    }

    /** @param list<string> $headers */
    private function proxy(array $headers, ?string $peer): Finding
    {
        $trusted = config('trustedproxy.proxies');
        $trusted = is_array($trusted) ? $trusted : array_filter(array_map('trim', explode(',', (string) $trusted)));

        if ($trusted === []) {
            return $headers === []
                ? Finding::ok('web.proxy', 'web_proxy_none')
                : Finding::warn('web.proxy', 'web_proxy_untrusted', ['list' => implode(', ', $headers)]);
        }
        // TrustProxies reads `*` and `**` only as the whole value; Request reads REMOTE_ADDR and PRIVATE_SUBNETS in a list.
        if (in_array(config('trustedproxy.proxies'), ['*', '**'], true) || in_array('REMOTE_ADDR', $trusted, true)) {
            return Finding::ok('web.proxy', 'web_proxy_trusted');
        }
        $ranges = array_merge(...array_map(
            fn (string $entry): array => in_array($entry, ['PRIVATE_SUBNETS', 'private_ranges'], true) ? IpUtils::PRIVATE_RANGES : [$entry],
            array_map('strval', $trusted),
        ));
        if ($peer !== null && IpUtils::checkIp($peer, $ranges)) {
            return Finding::ok('web.proxy', 'web_proxy_trusted');
        }

        return Finding::warn('web.proxy', 'web_proxy_peer_outside');
    }

    private function parent(string $parentUrl, string $name): Finding
    {
        $calibration = $this->write($this->layout->parent(), $name.'.txt');
        $releaseToken = $this->tokenAt($this->layout->base, $name);
        if ($calibration === null || $releaseToken === null) {
            return Finding::unknown('web.parent', 'web_cannot_write', ['path' => $calibration === null ? $this->layout->parent() : $this->layout->base]);
        }
        $page = $this->fetcher->get("{$parentUrl}/{$name}.txt");
        if (! $page->answered($calibration)) {
            return Finding::unknown('web.parent', 'web_parent_not_shown', ['url' => InstalledCheck::shownUrl($parentUrl), 'status' => $page->status ?? $page->reason()]);
        }

        $release = rawurlencode(basename($this->layout->base));
        $probes = [
            $this->layout->base => ["{$parentUrl}/{$release}/{$name}.txt", $releaseToken],
        ];
        if ($this->layout->storageInTree()) {
            $probes[$this->layout->storage] = ["{$parentUrl}/{$release}/storage/{$name}.txt", $this->tokenAt($this->layout->storage, $name)];
        }
        $data = $this->layout->dataDirectory();
        if (is_dir($data)) {
            $probes[$data] = ["{$parentUrl}/".SiteLayout::DATA_DIRECTORY."/{$name}.txt", $this->write($data, $name.'.txt')];
        }

        $served = [];
        foreach ($probes as $directory => [$probeUrl, $token]) {
            if ($token !== null && $this->fetcher->get($probeUrl)->answered($token)) {
                $served[] = $directory;
            }
        }

        return $served === []
            ? Finding::ok('web.parent', 'web_parent', ['url' => InstalledCheck::shownUrl($parentUrl)])
            : Finding::fail('web.parent', 'web_parent_served', ['url' => InstalledCheck::shownUrl($parentUrl), 'list' => implode(', ', $served)]);
    }

    /** A file this run wrote, with a random token unless the contents are given; null when it cannot be written. */
    private function write(string $directory, string $file, ?string $contents = null): ?string
    {
        $path = $directory.'/'.$file;
        $token = $contents === null ? bin2hex(random_bytes(16)) : '';
        // Listed before it exists, so a signal landing just after the write still has it removed.
        $this->written[] = $path;
        if (! is_dir($directory) || @file_put_contents($path, $contents ?? $token, LOCK_EX) === false) {
            return null;
        }
        $this->tokens[$path] = $token;

        return $token;
    }

    private function tokenAt(string $directory, string $name): ?string
    {
        return $this->tokens[$directory.'/'.$name.'.txt'] ?? null;
    }

    /** A killed run's files, old enough that no running check can still own them. */
    private function removeLeftovers(): void
    {
        $directories = [
            $this->layout->public, $this->layout->base, $this->layout->storage.'/framework',
            $this->layout->parent(), $this->layout->dataDirectory(),
        ];
        if ($this->layout->storageInTree()) {
            $directories[] = $this->layout->storage;
        }

        $cutoff = time() - self::LEFTOVER_SECONDS;
        foreach (array_unique($directories) as $directory) {
            foreach (@scandir($directory) ?: [] as $file) {
                $path = $directory.'/'.$file;
                if (preg_match(self::NAME_PATTERN, $file) === 1 && is_file($path) && (int) @filemtime($path) < $cutoff) {
                    @unlink($path);
                }
            }
        }
    }
}
