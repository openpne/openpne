<?php

declare(strict_types=1);

namespace Tests\Unit\LinkCard;

use App\LinkCard\OembedClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Tests\Concerns\FakesOutboundTransport;
use Tests\TestCase;

/**
 * The fetcher under these tests is the real SafeHttpFetcher; only the socket and the resolver are
 * fake (FakesOutboundTransport). So the destination check, the pin and the caps all run here, and a
 * client that reached the network some other way would fail rather than pass quietly.
 */
class OembedClientTest extends TestCase
{
    use FakesOutboundTransport;

    public function test_it_reads_the_structured_fields(): void
    {
        $this->resolvesTo('example.com', ['93.184.216.34']);
        $this->queueJson([
            'version' => '1.0',
            'type' => 'video',
            'title' => 'A talk',
            'provider_name' => 'Example Video',
            'author_name' => 'Someone',
            'thumbnail_url' => 'https://example.com/thumb.jpg',
        ]);

        $metadata = $this->client()->fetch('https://example.com/oembed?url=x');

        $this->assertSame('A talk', $metadata->title);
        $this->assertSame('Example Video', $metadata->siteName);
        $this->assertSame('Someone', $metadata->authorName);
        $this->assertSame('https://example.com/thumb.jpg', $metadata->imageUrl);
    }

    public function test_the_html_field_is_never_read(): void
    {
        // The whole reason this client exists in this shape. `html` is provider-authored markup —
        // an iframe, a script tag — and this app injects trusted HTML in exactly one place, from
        // exactly one producer. A card is text plus a self-hosted image, so there is nothing markup
        // could contribute that is worth the seam.
        $this->resolvesTo('example.com', ['93.184.216.34']);
        $this->queueJson([
            'version' => '1.0',
            'type' => 'rich',
            'title' => 'Embedded thing',
            'html' => '<iframe src="https://evil.example.net/"></iframe><script>alert(1)</script>',
        ]);

        $metadata = $this->client()->fetch('https://example.com/oembed');

        $this->assertSame('Embedded thing', $metadata->title);

        foreach (get_object_vars($metadata) as $field => $value) {
            $this->assertStringNotContainsString('<', (string) $value, "LinkMetadata::{$field} carries markup.");
            $this->assertStringNotContainsString('iframe', (string) $value, "LinkMetadata::{$field} carries the provider's html.");
        }
    }

    public function test_a_relative_thumbnail_resolves_against_the_endpoint(): void
    {
        $this->resolvesTo('example.com', ['93.184.216.34']);
        $this->queueJson(['version' => '1.0', 'type' => 'link', 'title' => 'T', 'thumbnail_url' => '/t.png']);

        $metadata = $this->client()->fetch('https://example.com/services/oembed?url=x');

        $this->assertSame('https://example.com/t.png', $metadata->imageUrl);
    }

    public function test_an_endpoint_resolving_to_a_private_address_yields_nothing(): void
    {
        // The endpoint URL came out of a stranger's markup, so it gets the same suspicion the page
        // did. This is the guard running for real, not a stub returning empty.
        $this->resolvesTo('oembed.internal', ['127.0.0.1']);
        $this->queueJson(['version' => '1.0', 'title' => 'Should never be read']);

        $metadata = $this->client()->fetch('https://oembed.internal/oembed');

        $this->assertNull($metadata->title);
        $this->assertSame([], $this->outboundRequests, 'The guard must refuse before a socket is opened.');
    }

    public function test_a_non_json_response_yields_nothing(): void
    {
        $this->resolvesTo('example.com', ['93.184.216.34']);
        $this->queueHtml('<html><head><title>Not oEmbed</title></head></html>');

        $this->assertNull($this->client()->fetch('https://example.com/oembed')->title);
    }

    public function test_a_non_200_response_yields_nothing(): void
    {
        $this->resolvesTo('example.com', ['93.184.216.34']);
        $this->queueResponse(new Response(404, ['Content-Type' => 'application/json'], '{"title":"Nope"}'));

        $this->assertNull($this->client()->fetch('https://example.com/oembed')->title);
    }

    public function test_malformed_json_yields_nothing(): void
    {
        $this->resolvesTo('example.com', ['93.184.216.34']);
        $this->queueResponse(new Response(200, ['Content-Type' => 'application/json'], '{"title": '));

        $this->assertNull($this->client()->fetch('https://example.com/oembed')->title);
    }

    public function test_non_string_fields_are_discarded(): void
    {
        // JSON promises nothing about types, and these values go on to be stored as strings.
        $this->resolvesTo('example.com', ['93.184.216.34']);
        $this->queueJson(['version' => '1.0', 'title' => ['an', 'array'], 'provider_name' => 42, 'author_name' => null]);

        $metadata = $this->client()->fetch('https://example.com/oembed');

        $this->assertNull($metadata->title);
        $this->assertNull($metadata->siteName);
        $this->assertNull($metadata->authorName);
    }

    public function test_control_characters_are_stripped_from_the_fields(): void
    {
        $this->resolvesTo('example.com', ['93.184.216.34']);
        $this->queueJson(['version' => '1.0', 'title' => "Two\r\nlines\ttabbed"]);

        $this->assertSame('Two lines tabbed', $this->client()->fetch('https://example.com/oembed')->title);
    }

    public function test_an_unreachable_endpoint_yields_nothing_rather_than_throwing(): void
    {
        // This is an optional enrichment on top of what the page already said, so failing it must
        // never turn a working card into no card.
        $this->resolvesTo('example.com', ['93.184.216.34']);
        $this->queueResponse(new ConnectException('boom', new Request('GET', 'https://example.com')));

        $this->assertNull($this->client()->fetch('https://example.com/oembed')->title);
    }

    private function client(): OembedClient
    {
        return new OembedClient($this->fakeFetcher());
    }
}
