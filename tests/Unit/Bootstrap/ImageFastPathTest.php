<?php

namespace Tests\Unit\Bootstrap;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../bootstrap/image-fast-path.php';

class ImageFastPathTest extends TestCase
{
    private const KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const BINDING = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const VERSION = '0123abcd';

    private const NAME = 'Tok_en-1';

    private string $storage;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir().'/openpne-fast-path-'.bin2hex(random_bytes(6));
        $this->root = $this->storage.'/cache';
        mkdir($this->storage.'/framework', 0777, true);
        mkdir($this->root.'/'.self::NAME.'/g3/gd-q85', 0777, true);
        file_put_contents($this->root.'/'.self::NAME.'/g3/gd-q85/w120_h120_sq.png', 'PNGBYTES');
        $this->publish();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->storage));

        parent::tearDown();
    }

    public function test_a_signed_url_with_its_cookie_names_the_cached_variant(): void
    {
        $answer = openpne_image_fast_path($this->server(), $this->query(), $this->cookies(), $this->storage);

        $this->assertSame([
            'file' => $this->root.'/'.self::NAME.'/g3/gd-q85/w120_h120_sq.png',
            'type' => 'image/png',
            'etag' => '"'.hash('sha1', self::NAME.'/g3/gd-q85/w120_h120_sq.png').'"',
        ], $answer);
    }

    public function test_head_a_port_an_upper_case_host_and_a_sub_path_are_answered_too(): void
    {
        $server = ['REQUEST_METHOD' => 'HEAD', 'HTTP_HOST' => 'SNS.Example.test:8443', 'REQUEST_URI' => '/sub/dir'.$this->path().'?v='.self::VERSION] + $this->server();

        $this->assertNotNull(openpne_image_fast_path($server, $this->query(), $this->cookies(), $this->storage));
    }

    public function test_the_secure_cookie_name_is_read_when_the_file_names_it(): void
    {
        $this->publish(['cookie' => '__Host-openpne-img']);

        $this->assertNotNull(openpne_image_fast_path($this->server(), $this->query(), ['__Host-openpne-img' => self::BINDING], $this->storage));
        $this->assertNull(openpne_image_fast_path($this->server(), $this->query(), $this->cookies(), $this->storage));
    }

    /**
     * @param  array<string, mixed>  $server
     * @param  array<string, mixed>|null  $query
     * @param  array<string, mixed>|null  $cookies
     */
    #[DataProvider('refusedRequests')]
    public function test_anything_else_is_left_to_the_application(array $server, ?array $query, ?array $cookies): void
    {
        $this->assertNull(openpne_image_fast_path($server + $this->server(), $query ?? $this->query(), $cookies ?? $this->cookies(), $this->storage));
    }

    /** @return array<string, array{array<string, mixed>, array<string, mixed>|null, array<string, mixed>|null}> */
    public static function refusedRequests(): array
    {
        $path = '/cache/img/png/w120_h120_sq/'.self::NAME.'.png';
        $signature = self::signature('png/w120_h120_sq/'.self::NAME);
        $query = ['v' => self::VERSION, 's' => $signature];

        return [
            'post' => [['REQUEST_METHOD' => 'POST'], null, null],
            'no method' => [['REQUEST_METHOD' => null], null, null],
            'conditional' => [['HTTP_IF_NONE_MATCH' => '"x"'], null, null],
            'another host' => [['HTTP_HOST' => 'evil.example.test'], null, null],
            'a sibling host' => [['HTTP_HOST' => 'other.sns.example.test'], null, null],
            'host as an array' => [['HTTP_HOST' => ['sns.example.test']], null, null],
            'no host' => [['HTTP_HOST' => null], null, null],
            'uri as an array' => [['REQUEST_URI' => [$path]], null, null],
            'wrong signature' => [[], ['s' => str_repeat('A', 22)] + $query, null],
            'signature of another geometry' => [[], ['s' => self::signature('png/w120_h120/'.self::NAME)] + $query, null],
            'signature as an array' => [[], ['s' => [$signature]] + $query, null],
            'short signature' => [[], ['s' => substr($signature, 0, 21)] + $query, null],
            'no signature' => [[], ['v' => self::VERSION], null],
            'another version' => [[], ['v' => 'ffffffff'] + $query, null],
            'version as an array' => [[], ['v' => [self::VERSION]] + $query, null],
            'no cookie' => [[], null, []],
            'another cookie' => [[], null, ['openpne-img' => str_repeat('c', 64)]],
            'cookie as an array' => [[], null, ['openpne-img' => [self::BINDING]]],
            'oversized cookie' => [[], null, ['openpne-img' => str_repeat('b', 4096)]],
            'cookie under the other name' => [[], null, ['__Host-openpne-img' => self::BINDING]],
            'the canonical' => [['REQUEST_URI' => '/cache/img/png/w_h/'.self::NAME.'.png'], ['s' => self::signature('png/w_h/'.self::NAME)] + $query, null],
            'an OpenPNE 3 name' => [['REQUEST_URI' => '/cache/img/png/w120_h120_sq/m1_abc.def.png'], ['s' => self::signature('png/w120_h120_sq/m1_abc.def')] + $query, null],
            'a climbing name' => [['REQUEST_URI' => '/cache/img/png/w120_h120_sq/..%2F..%2Fx.png'], null, null],
            'mismatched extension' => [['REQUEST_URI' => '/cache/img/png/w120_h120_sq/'.self::NAME.'.jpg'], null, null],
            'a suffix after the path' => [['REQUEST_URI' => $path.'/x'], null, null],
        ];
    }

    /** @param  array<string, mixed>|string|null  $contents */
    #[DataProvider('unusableFiles')]
    public function test_a_file_it_cannot_trust_switches_it_off(array|string|null $contents): void
    {
        $file = $this->storage.'/framework/image-fast-path.json';

        match (true) {
            $contents === null => unlink($file),
            is_string($contents) => file_put_contents($file, $contents),
            default => $this->publish($contents),
        };

        $this->assertNull(openpne_image_fast_path($this->server(), $this->query(), $this->cookies(), $this->storage));
    }

    /** @return array<string, array{array<string, mixed>|string|null}> */
    public static function unusableFiles(): array
    {
        return [
            'missing' => [null],
            'not json' => ['{'],
            'a json string' => ['"x"'],
            'another schema' => [['schema' => 2]],
            'schema as a string' => [['schema' => '1']],
            'key as an array' => [['key' => [self::KEY]]],
            'short key' => [['key' => 'abc']],
            'relative root' => [['root' => 'cache']],
            'root as a number' => [['root' => 1]],
            'encoder that climbs' => [['encoder' => 'g3/../..']],
            'encoder as null' => [['encoder' => null]],
            'unknown cookie name' => [['cookie' => 'openpne-session']],
            'host as an array' => [['host' => ['sns.example.test']]],
            'version as a number' => [['version' => 1234]],
        ];
    }

    public function test_a_variant_that_is_not_on_the_disk_is_not_served(): void
    {
        $query = ['v' => self::VERSION, 's' => self::signature('png/w76_h76_sq/'.self::NAME)];
        $server = ['REQUEST_URI' => '/cache/img/png/w76_h76_sq/'.self::NAME.'.png'] + $this->server();

        $this->assertNotNull(openpne_image_fast_path($server, $query, $this->cookies(), $this->storage));
        $this->assertFalse(openpne_image_fast_path_serve($server, $query, $this->cookies(), $this->storage));
    }

    public function test_a_directory_at_the_variant_path_is_not_served(): void
    {
        mkdir($this->root.'/'.self::NAME.'/g3/gd-q85/w76_h76_sq.png');
        $query = ['v' => self::VERSION, 's' => self::signature('png/w76_h76_sq/'.self::NAME)];
        $server = ['REQUEST_URI' => '/cache/img/png/w76_h76_sq/'.self::NAME.'.png'] + $this->server();

        $this->assertFalse(openpne_image_fast_path_serve($server, $query, $this->cookies(), $this->storage));
    }

    public function test_a_refused_request_sends_nothing(): void
    {
        ob_start();
        $served = openpne_image_fast_path_serve(['REQUEST_METHOD' => 'POST'] + $this->server(), $this->query(), $this->cookies(), $this->storage);
        $output = ob_get_clean();

        $this->assertFalse($served);
        $this->assertSame('', $output);
    }

    /** @param  array<string, mixed>  $overrides */
    private function publish(array $overrides = []): void
    {
        file_put_contents($this->storage.'/framework/image-fast-path.json', json_encode($overrides + [
            'schema' => 1,
            'key' => self::KEY,
            'host' => 'sns.example.test',
            'root' => $this->root,
            'encoder' => 'g3/gd-q85',
            'version' => self::VERSION,
            'cookie' => 'openpne-img',
        ]));
    }

    private function path(): string
    {
        return '/cache/img/png/w120_h120_sq/'.self::NAME.'.png';
    }

    /** @return array<string, mixed> */
    private function server(): array
    {
        return ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'sns.example.test', 'REQUEST_URI' => $this->path().'?v='.self::VERSION];
    }

    /** @return array<string, mixed> */
    private function query(): array
    {
        return ['v' => self::VERSION, 's' => self::signature('png/w120_h120_sq/'.self::NAME)];
    }

    /** @return array<string, mixed> */
    private function cookies(): array
    {
        return ['openpne-img' => self::BINDING];
    }

    private static function signature(string $resource): string
    {
        return openpne_image_fast_path_signature(self::KEY, $resource, self::VERSION, self::BINDING);
    }
}
