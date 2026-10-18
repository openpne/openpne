<?php

namespace Tests\Feature\Http;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class DeclareContentLengthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_body_declares_its_byte_length(): void
    {
        Route::get('/_probe/text', fn () => response('こんにちは'));
        Route::get('/_probe/json', fn () => response()->json(['ok' => true]));

        $this->get('/_probe/text')->assertHeader('Content-Length', (string) strlen('こんにちは'));
        $this->get('/_probe/json')->assertHeader('Content-Length', (string) strlen('{"ok":true}'));
    }

    public function test_the_length_is_of_the_body_livewire_finished_injecting_into(): void
    {
        Filament::setCurrentPanel('admin');

        $response = $this->get('/admin/login');

        $response->assertOk();
        $this->assertStringContainsString('livewire', $response->getContent());
        $this->assertSame(strlen($response->getContent()), (int) $response->headers->get('Content-Length'));
    }

    public function test_a_length_a_controller_declared_is_replaced_by_the_measured_one(): void
    {
        Route::get('/_probe/stale', fn () => response('abcdef')->header('Content-Length', '3'));

        $this->get('/_probe/stale')->assertHeader('Content-Length', '6');
    }

    public function test_a_head_reply_is_not_declared_empty(): void
    {
        Route::get('/_probe/head', fn () => response('abc'));

        $this->head('/_probe/head')->assertHeaderMissing('Content-Length');
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
