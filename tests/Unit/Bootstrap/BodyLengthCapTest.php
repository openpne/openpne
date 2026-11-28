<?php

namespace Tests\Unit\Bootstrap;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../bootstrap/body-length-cap.php';

class BodyLengthCapTest extends TestCase
{
    /** @return resource */
    private function body(int $length)
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, str_repeat('a', $length));
        rewind($stream);

        return $stream;
    }

    /** @return array<string, array{string, string}> */
    public static function coveredTypes(): array
    {
        return [
            'json' => ['POST', 'application/json'],
            'json with a charset' => ['POST', 'application/json; charset=UTF-8'],
            'a +json type' => ['PATCH', 'application/vnd.api+json'],
            'text' => ['POST', 'text/plain'],
            'octet stream' => ['PUT', 'application/octet-stream'],
            'no type' => ['POST', ''],
            'a GET with a body' => ['GET', 'application/json'],
            'a form type naming json after a comma' => ['POST', 'application/x-www-form-urlencoded, application/json'],
            'a form type naming json in a parameter' => ['POST', 'multipart/form-data; boundary=x/json'],
            'a type that only starts like a form' => ['POST', 'application/x-www-form-urlencodedx'],
        ];
    }

    #[DataProvider('coveredTypes')]
    public function test_a_body_that_is_not_a_form_is_held_to_the_limit(string $method, string $type): void
    {
        $server = ['REQUEST_METHOD' => $method, 'CONTENT_TYPE' => $type];

        $this->assertFalse(openpne_body_length_exceeded($server, $this->body(OPENPNE_MAX_BODY_BYTES)));
        $this->assertTrue(openpne_body_length_exceeded($server, $this->body(OPENPNE_MAX_BODY_BYTES + 1)));
    }

    /** @return array<string, array{string, string}> */
    public static function formTypes(): array
    {
        return [
            'urlencoded' => ['POST', 'application/x-www-form-urlencoded'],
            'urlencoded with a charset, in capitals' => ['POST', 'Application/X-WWW-Form-Urlencoded;charset=UTF-8'],
            'multipart' => ['POST', 'multipart/form-data; boundary=----x'],
            'cut at a comma, as PHP cuts it' => ['POST', 'application/x-www-form-urlencoded, text/plain'],
            'cut at a space, as PHP cuts it' => ['POST', 'application/x-www-form-urlencoded charset=UTF-8'],
            'a PUT urlencoded' => ['PUT', 'application/x-www-form-urlencoded'],
            'a PUT multipart' => ['PUT', 'multipart/form-data; boundary=----x'],
        ];
    }

    #[DataProvider('formTypes')]
    public function test_a_form_is_left_to_post_max_size(string $method, string $type): void
    {
        $server = ['REQUEST_METHOD' => $method, 'CONTENT_TYPE' => $type, 'CONTENT_LENGTH' => (string) (OPENPNE_MAX_BODY_BYTES + 1)];

        $this->assertFalse(openpne_body_length_exceeded($server, $this->body(OPENPNE_MAX_BODY_BYTES + 1)));
    }

    public function test_a_form_type_is_in_scope_when_the_header_copy_names_json(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_CONTENT_TYPE' => 'application/json',
        ];

        $this->assertTrue(openpne_body_length_exceeded($server, $this->body(OPENPNE_MAX_BODY_BYTES + 1)));
    }

    public function test_a_declared_length_over_the_limit_is_refused_before_reading(): void
    {
        $body = $this->body(0);

        $this->assertTrue(openpne_body_exceeds(['CONTENT_LENGTH' => (string) (OPENPNE_MAX_BODY_BYTES + 1)], $body));
        $this->assertSame(0, ftell($body));
    }

    public function test_a_short_declared_length_is_measured_by_what_arrives(): void
    {
        $this->assertTrue(openpne_body_exceeds(['CONTENT_LENGTH' => '10'], $this->body(OPENPNE_MAX_BODY_BYTES + 1)));
        $this->assertTrue(openpne_body_exceeds(['CONTENT_LENGTH' => 'x'], $this->body(OPENPNE_MAX_BODY_BYTES + 1)));
        $this->assertFalse(openpne_body_exceeds(['CONTENT_LENGTH' => (string) OPENPNE_MAX_BODY_BYTES], $this->body(OPENPNE_MAX_BODY_BYTES)));
    }

    public function test_no_more_than_one_byte_past_the_limit_is_read(): void
    {
        $body = $this->body(OPENPNE_MAX_BODY_BYTES * 2);

        $this->assertTrue(openpne_body_exceeds([], $body));
        $this->assertSame(OPENPNE_MAX_BODY_BYTES + 1, ftell($body));
    }

    public function test_a_body_that_cannot_be_opened_is_refused(): void
    {
        $this->assertTrue(openpne_body_exceeds([], false));
    }

    public function test_the_refusal_is_the_documented_one(): void
    {
        $this->assertSame([
            'Content-Type' => 'application/json',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ], OPENPNE_BODY_TOO_LARGE_HEADERS);
        $this->assertSame(['message' => 'The request body is too large.'], json_decode(OPENPNE_BODY_TOO_LARGE_BODY, true));
    }

    public function test_the_entry_point_answers_413_without_loading_the_autoloader(): void
    {
        $storage = sys_get_temp_dir().'/openpne-body-cap-'.bin2hex(random_bytes(6));
        mkdir($storage);
        $driver = sprintf(<<<'PHP'
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['CONTENT_TYPE'] = 'application/json';
            $_SERVER['CONTENT_LENGTH'] = '%d';
            register_shutdown_function(function () {
                fwrite(STDERR, json_encode(['status' => http_response_code(), 'files' => get_included_files()]));
            });
            require $argv[1];
            PHP, OPENPNE_MAX_BODY_BYTES + 1);

        $process = proc_open(
            [PHP_BINARY, '-r', $driver, '--', realpath(__DIR__.'/../../../public/index.php')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [...getenv(), 'LARAVEL_STORAGE_PATH' => $storage],
        );
        fclose($pipes[0]);
        // Past a missing refusal the child boots the application, which need not exit on its own.
        $deadline = microtime(true) + 10;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(20000);
        }
        $finished = ! proc_get_status($process)['running'];
        if (! $finished) {
            proc_terminate($process, 9);
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = stream_get_contents($pipes[1]);
        $report = json_decode((string) stream_get_contents($pipes[2]), true);
        proc_close($process);
        rmdir($storage);

        $this->assertTrue($finished, 'the entry point did not answer on its own');

        $this->assertSame(OPENPNE_BODY_TOO_LARGE_BODY, $stdout);
        $this->assertSame(413, $report['status'] ?? null);
        $files = implode("\n", $report['files'] ?? []);
        $this->assertStringContainsString('bootstrap/body-length-cap.php', $files);
        $this->assertStringNotContainsString('vendor/autoload.php', $files);
    }

    public function test_the_entry_point_checks_after_the_early_answers_and_before_the_autoloader(): void
    {
        $index = (string) file_get_contents(__DIR__.'/../../../public/index.php');

        $maintenance = strpos($index, 'require $maintenance;');
        $image = strpos($index, 'openpne_image_fast_path_serve(');
        $check = strpos($index, 'openpne_body_length_exceeded($_SERVER)');
        $autoload = strpos($index, "vendor/autoload.php'");

        $this->assertIsInt($maintenance);
        $this->assertIsInt($check);
        $this->assertTrue($maintenance < $image && $image < $check && $check < $autoload);
    }
}
