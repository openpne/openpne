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
