<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class SetContentLengthTest extends TestCase
{
    public function test_a_body_declares_its_byte_length(): void
    {
        Route::get('/_probe/text', fn () => response('こんにちは'));
        Route::get('/_probe/json', fn () => response()->json(['ok' => true]));

        $this->get('/_probe/text')->assertHeader('Content-Length', (string) strlen('こんにちは'));
        $this->get('/_probe/json')->assertHeader('Content-Length', (string) strlen('{"ok":true}'));
    }

    public function test_an_existing_length_is_kept(): void
    {
        Route::get('/_probe/kept', fn () => response('abc')->header('Content-Length', '3'));

        $this->get('/_probe/kept')->assertHeader('Content-Length', '3');
    }

    public function test_bodiless_and_streamed_replies_get_none(): void
    {
        Route::get('/_probe/none', fn () => response()->noContent());
        Route::get('/_probe/stream', fn () => new StreamedResponse(function (): void {
            echo 'chunk';
        }));
        Route::get('/_probe/chunked', fn () => response('abc')->header('Transfer-Encoding', 'chunked'));

        $this->get('/_probe/none')->assertHeaderMissing('Content-Length');
        $this->get('/_probe/stream')->assertHeaderMissing('Content-Length');
        $this->get('/_probe/chunked')->assertHeaderMissing('Content-Length');
    }
}
