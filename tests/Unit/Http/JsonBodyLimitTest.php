<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Files\UploadLimit;
use App\Http\JsonBodyLimit;
use App\Mcp\Tools\DiaryTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use Tests\Fixtures\CountedByteStream;

final class JsonBodyLimitTest extends TestCase
{
    private const JSON = ['CONTENT_TYPE' => 'application/json'];

    /** @return array<string, array{string}> */
    public static function jsonTypes(): array
    {
        return [
            'application/json' => ['application/json'],
            'with a charset' => ['application/json; charset=utf-8'],
            'x-json' => ['application/x-json'],
            'a +json suffix' => ['application/vnd.api+json'],
            'upper case' => ['Application/JSON'],
            '/json in a parameter, which isJson() matches' => ['application/octet-stream; x=/json'],
            '+json in a parameter, which isJson() matches' => ['text/plain; x=+json'],
        ];
    }

    #[DataProvider('jsonTypes')]
    public function test_a_json_type_is_measured(string $type): void
    {
        $this->assertTrue($this->exceeded(['CONTENT_TYPE' => $type], self::separators(JsonBodyLimit::MAX_SEPARATORS + 1)));
    }

    public function test_the_type_is_also_taken_from_the_header_variable(): void
    {
        $this->assertTrue($this->exceeded(['HTTP_CONTENT_TYPE' => 'application/json'], self::separators(JsonBodyLimit::MAX_SEPARATORS + 1)));
    }

    /** @return array<string, array{array<string, string>}> */
    public static function otherTypes(): array
    {
        return [
            'no type' => [[]],
            'a form' => [['CONTENT_TYPE' => 'application/x-www-form-urlencoded']],
            'a multipart boundary that spells json' => [['CONTENT_TYPE' => 'multipart/form-data; boundary=----WebKitFormBoundaryJsONx1y2z3w4v5u6']],
            'text' => [['CONTENT_TYPE' => 'text/plain']],
        ];
    }

    /** The declared length is over the cap too, so only the type keeps these out. */
    #[DataProvider('otherTypes')]
    public function test_any_other_type_is_left_unread(array $server): void
    {
        $this->assertFalse(JsonBodyLimit::exceeded($server + ['CONTENT_LENGTH' => (string) (2 * JsonBodyLimit::MAX_BYTES)], self::counted(2 * JsonBodyLimit::MAX_BYTES)));
        $this->assertSame(0, CountedByteStream::consumed());
    }

    public function test_a_declared_length_over_the_cap_is_refused_unread(): void
    {
        $this->assertTrue(JsonBodyLimit::exceeded(self::JSON + ['CONTENT_LENGTH' => (string) (JsonBodyLimit::MAX_BYTES + 1)], self::counted(2)));
        $this->assertSame(0, CountedByteStream::consumed());

        $this->assertFalse($this->exceeded(self::JSON + ['CONTENT_LENGTH' => (string) JsonBodyLimit::MAX_BYTES], '{}'));
    }

    /** A chunked request declares no length, so the read is what bounds the bytes held. */
    public function test_an_undeclared_body_is_read_only_one_byte_past_the_cap(): void
    {
        $this->assertTrue(JsonBodyLimit::exceeded(self::JSON, self::counted(2 * JsonBodyLimit::MAX_BYTES)));
        $this->assertLessThanOrEqual(JsonBodyLimit::MAX_BYTES + 1 + CountedByteStream::SLACK, CountedByteStream::consumed());
    }

    public function test_the_length_cap_admits_its_own_size(): void
    {
        $this->assertFalse($this->exceeded(self::JSON, self::string(JsonBodyLimit::MAX_BYTES)));
        $this->assertTrue($this->exceeded(self::JSON, self::string(JsonBodyLimit::MAX_BYTES + 1)));
    }

    public function test_the_separator_cap_admits_its_own_count(): void
    {
        $this->assertFalse($this->exceeded(self::JSON, self::separators(JsonBodyLimit::MAX_SEPARATORS)));
        $this->assertTrue($this->exceeded(self::JSON, self::separators(JsonBodyLimit::MAX_SEPARATORS + 1)));
    }

    /** @return array<string, array{string}> */
    public static function separatorCharacters(): array
    {
        return ['comma' => [','], 'bracket' => ['['], 'brace' => ['{']];
    }

    /** Inside a string, so the body stays one valid value whichever character it is. */
    #[DataProvider('separatorCharacters')]
    public function test_each_separator_counts_inside_a_string_too(string $character): void
    {
        $this->assertFalse($this->exceeded(self::JSON, '"'.str_repeat($character, JsonBodyLimit::MAX_SEPARATORS).'"'));
        $this->assertTrue($this->exceeded(self::JSON, '"'.str_repeat($character, JsonBodyLimit::MAX_SEPARATORS + 1).'"'));
    }

    public function test_the_key_cap_counts_colons_inside_a_string_too(): void
    {
        $this->assertFalse($this->exceeded(self::JSON, '"'.str_repeat(':', JsonBodyLimit::MAX_KEYS).'"'));
        $this->assertTrue($this->exceeded(self::JSON, '"'.str_repeat(':', JsonBodyLimit::MAX_KEYS + 1).'"'));
    }

    /** @return array<string, array{int}> */
    public static function longBodies(): array
    {
        return [
            'at the key length' => [JsonBodyLimit::MAX_KEYS_LENGTH],
            'twice the key length' => [2 * JsonBodyLimit::MAX_KEYS_LENGTH],
            'at the length cap' => [JsonBodyLimit::MAX_BYTES],
        ];
    }

    #[DataProvider('longBodies')]
    public function test_past_the_key_length_the_key_cap_shrinks_in_proportion(int $length): void
    {
        $keys = intdiv(JsonBodyLimit::MAX_KEYS * JsonBodyLimit::MAX_KEYS_LENGTH, $length);

        $this->assertFalse($this->exceeded(self::JSON, self::colons($keys, $length)));
        $this->assertTrue($this->exceeded(self::JSON, self::colons($keys + 1, $length)));
    }

    /** @return array<string, array{int, int}> */
    public static function keyLimitsInNumbers(): array
    {
        return [
            'a short body' => [15_001, 15_003],
            'a mebibyte' => [7_501, 1024 * 1024],
            'eight mebibytes' => [938, 8 * 1024 * 1024],
        ];
    }

    /** In numbers, so that raising a key limit has to change this test too. */
    #[DataProvider('keyLimitsInNumbers')]
    public function test_the_key_limits_refuse_these_counts(int $keys, int $length): void
    {
        $this->assertTrue($this->exceeded(self::JSON, self::colons($keys, $length)));
    }

    /** A stylesheet at the setting's byte cap, twice, as an admin save carries it in the snapshot and the update. */
    public function test_the_key_cap_admits_a_full_custom_stylesheet_saved_from_the_admin_form(): void
    {
        $css = str_repeat('.a{color:#000}', intdiv(65535, 14));

        $this->assertFalse($this->exceeded(self::JSON, (string) json_encode([$css, $css])));
    }

    public function test_colons_also_count_toward_the_separator_cap(): void
    {
        $colons = str_repeat(':', JsonBodyLimit::MAX_KEYS);
        $commas = JsonBodyLimit::MAX_SEPARATORS - JsonBodyLimit::MAX_KEYS;

        $this->assertFalse($this->exceeded(self::JSON, '"'.$colons.str_repeat(',', $commas).'"'));
        $this->assertTrue($this->exceeded(self::JSON, '"'.$colons.str_repeat(',', $commas + 1).'"'));
    }

    /** The decode alone, as the framework's copies of the body and its strings come on top whatever the caps are. */
    public function test_a_body_at_both_caps_decodes_within_sixteen_mebibytes(): void
    {
        $nested = str_repeat('[0],', intdiv(JsonBodyLimit::MAX_SEPARATORS, 2) - 1);
        $body = '['.$nested.'"'.str_repeat('x', JsonBodyLimit::MAX_BYTES - strlen($nested) - 6).'"]';
        $this->assertFalse($this->exceeded(self::JSON, $body));

        gc_collect_cycles();
        $before = memory_get_usage();
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $used = memory_get_usage() - $before;

        $this->assertIsArray($decoded);
        $this->assertLessThan(16 * 1024 * 1024, $used);
    }

    public function test_the_refusal_is_a_413_with_a_json_message(): void
    {
        $response = JsonBodyLimit::refusal(['SERVER_PROTOCOL' => 'HTTP/1.1']);

        $this->assertSame(413, $response->getStatusCode());
        $this->assertSame(['message' => 'The JSON body is too large.'], json_decode((string) $response->getContent(), true));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('1.1', $response->getProtocolVersion());
        $this->assertSame('1.1', JsonBodyLimit::refusal(['SERVER_PROTOCOL' => 'HTTP/2.0'])->getProtocolVersion());
        $this->assertSame('1.0', JsonBodyLimit::refusal(['SERVER_PROTOCOL' => 'HTTP/1.0'])->getProtocolVersion());
    }

    public function test_the_front_controller_refuses_after_autoloading_and_before_the_framework(): void
    {
        $index = (string) file_get_contents(dirname(__DIR__, 3).'/public/index.php');

        $autoload = strpos($index, "require __DIR__.'/../vendor/autoload.php';");
        $gate = strpos($index, "if (JsonBodyLimit::exceeded(\$_SERVER)) {\n    JsonBodyLimit::refusal(\$_SERVER)->send();\n    exit;\n}");
        $framework = strpos($index, "require_once __DIR__.'/../bootstrap/app.php';");

        $this->assertIsInt($autoload);
        $this->assertIsInt($gate);
        $this->assertIsInt($framework);
        $this->assertTrue($autoload < $gate && $gate < $framework);
    }

    /** Title and body at their byte cap, each byte escaped to six as json_encode() writes a control character. */
    public function test_an_mcp_post_with_one_picture_at_the_shipped_cap_fits(): void
    {
        $text = str_repeat("\x01", (int) (new ReflectionClassConstant(DiaryTool::class, 'BODY_MAX_BYTES'))->getValue());
        $picture = str_repeat('A', intdiv(UploadLimit::DEFAULT_KILOBYTES * 1024 + 2, 3) * 4);

        $request = (string) json_encode(['jsonrpc' => '2.0', 'id' => PHP_INT_MAX, 'method' => 'tools/call', 'params' => [
            'name' => 'post-diary',
            'arguments' => ['title' => $text, 'body' => $text, 'images' => [$picture]],
        ]]);

        // A kilobyte left for the arguments not written here.
        $this->assertLessThanOrEqual(JsonBodyLimit::MAX_BYTES - 1024, strlen($request));
        $this->assertFalse($this->exceeded(self::JSON, $request));
    }

    /** @param  array<string, string>  $server */
    private function exceeded(array $server, string $body): bool
    {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, $body);
        rewind($stream);

        try {
            return JsonBodyLimit::exceeded($server, $stream);
        } finally {
            fclose($stream);
        }
    }

    /** @return resource */
    private static function counted(int $size)
    {
        CountedByteStream::prepare($size);

        return CountedByteStream::open();
    }

    /** A JSON array of $count values, holding one separator per value. */
    private static function separators(int $count): string
    {
        return '['.implode(',', array_fill(0, $count, '0')).']';
    }

    /** A JSON string $length bytes long holding $count colons. */
    private static function colons(int $count, int $length): string
    {
        return '"'.str_repeat(':', $count).str_repeat('x', $length - $count - 2).'"';
    }

    private static function string(int $length): string
    {
        return '"'.str_repeat('x', $length - 2).'"';
    }
}
