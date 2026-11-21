<?php

declare(strict_types=1);

namespace Tests\Feature\SiteCheck;

use App\Outbound\CappedStream;
use App\SiteCheck\SiteFetcher;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class SiteFetcherTest extends TestCase
{
    public function test_every_fetch_writes_into_its_own_capped_sink(): void
    {
        $sinks = [];
        Http::fake(function (Request $request, array $options) use (&$sinks) {
            $sinks[] = $options[RequestOptions::SINK];

            return Http::response('ok');
        });

        (new SiteFetcher)->get('https://sns.example.com/a');
        (new SiteFetcher)->get('https://sns.example.com/b');

        $this->assertContainsOnlyInstancesOf(CappedStream::class, $sinks);
        $this->assertCount(2, $sinks);
        $this->assertNotSame($sinks[0], $sinks[1]);
    }

    /** The handler plays Guzzle's curl handler on libcurl's write error, which Http::fake cannot: it throws where curl rejects. */
    public function test_a_body_past_the_cap_is_cut_and_keeps_its_status(): void
    {
        foreach ([200, 404] as $status) {
            $handler = function (RequestInterface $request, array $options) use ($status): PromiseInterface {
                $options[RequestOptions::SINK]->write(str_repeat('x', 5 * 1024 * 1024));

                return Create::rejectionFor(new ResponseException('Unable to write to stream', $request, new Response($status)));
            };
            $stack = HandlerStack::create($handler);
            $stack->push(SiteFetcher::cappedBody());

            $response = (new Client(['handler' => $stack, 'http_errors' => false]))->get('https://sns.example.com/');

            $this->assertSame($status, $response->getStatusCode());
            $this->assertSame(SiteFetcher::MAX_BYTES, strlen((string) $response->getBody()));
        }
    }

    public function test_a_transfer_cut_short_for_any_other_reason_stays_a_failure(): void
    {
        $handler = fn (RequestInterface $request): PromiseInterface => Create::rejectionFor(
            new ResponseTransferException('cURL error 18: transfer closed with outstanding read data remaining', $request, new Response(200)),
        );
        $stack = HandlerStack::create($handler);
        $stack->push(SiteFetcher::cappedBody());

        $this->expectException(ResponseTransferException::class);
        (new Client(['handler' => $stack, 'http_errors' => false]))->get('https://sns.example.com/');
    }

    public function test_a_failed_transfer_is_a_page_without_status(): void
    {
        Http::fake(fn (Request $request) => Create::rejectionFor(
            new ResponseTransferException('cURL error 18: transfer closed with outstanding read data remaining', $request->toPsrRequest(), new Response(200)),
        ));

        $page = (new SiteFetcher)->get('https://sns.example.com/');

        $this->assertNull($page->status);
        $this->assertStringContainsString('cURL error 18', $page->reason());
    }
}
