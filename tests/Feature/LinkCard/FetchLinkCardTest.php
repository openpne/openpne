<?php

declare(strict_types=1);

namespace Tests\Feature\LinkCard;

use App\Files\FileUploader;
use App\Jobs\FetchLinkCard;
use App\LinkCard\LinkCardImage;
use App\LinkCard\LinkCardSettings;
use App\LinkCard\LinkUrl;
use App\LinkCard\MetadataExtractor;
use App\LinkCard\OembedClient;
use App\Models\LinkCard;
use App\Outbound\SafeHttpFetcher;
use App\Support\LinkCardStatus;
use App\Support\SnsSettingKey;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Intervention\Image\ImageManager;
use Tests\Concerns\FakesOutboundTransport;
use Tests\TestCase;

/**
 * The fetch runs against a real SafeHttpFetcher with only the socket and resolver faked, so the
 * destination check, the connection pin and the caps all execute here too.
 */
class FetchLinkCardTest extends TestCase
{
    use FakesOutboundTransport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, true);
        $this->resolvesTo('example.com', ['93.184.216.34']);
    }

    public function test_it_fills_in_a_card_from_the_page(): void
    {
        $card = $this->card('https://example.com/article');
        $this->queueHtml(<<<'HTML'
            <html><head>
            <meta property="og:title" content="An article">
            <meta property="og:description" content="About something">
            <meta property="og:site_name" content="Example">
            </head></html>
            HTML);

        $this->runJob($card);

        $card->refresh();
        $this->assertSame(LinkCardStatus::Ok, $card->status);
        $this->assertSame('An article', $card->title);
        $this->assertSame('About something', $card->description);
        $this->assertSame('Example', $card->site_name);
        $this->assertTrue($card->isRenderable());
        $this->assertNotNull($card->expires_at);
        $this->assertNull($card->next_attempt_at, 'The lease must be released so a later refresh can claim it.');
    }

    public function test_a_page_that_says_nothing_about_itself_is_recorded_as_a_failure(): void
    {
        // Reached and read, with nothing to show. That is an answer, so it is cached rather than
        // retried on the next view.
        $card = $this->card('https://example.com/bare');
        $this->queueHtml('<html><body><p>Hello</p></body></html>');

        $this->runJob($card);

        $card->refresh();
        $this->assertSame(LinkCardStatus::Failed, $card->status);
        $this->assertSame(1, $card->failure_count);
        $this->assertNotNull($card->next_attempt_at);
    }

    public function test_a_non_html_response_is_a_failure(): void
    {
        $card = $this->card('https://example.com/file.zip');
        $this->queueResponse(new Response(200, ['Content-Type' => 'application/zip'], 'PK'));

        $this->runJob($card);

        $this->assertSame(LinkCardStatus::Failed, $card->fresh()?->status);
    }

    public function test_a_url_the_guard_refuses_is_a_failure_with_no_request(): void
    {
        $this->resolvesTo('internal.example.com', ['127.0.0.1']);
        $card = $this->card('https://internal.example.com/');
        $this->queueHtml('<html><head><title>Never read</title></head></html>');

        $this->runJob($card);

        $this->assertSame(LinkCardStatus::Failed, $card->fresh()?->status);
        $this->assertSame([], $this->outboundRequests, 'The guard must refuse before a socket is opened.');
    }

    public function test_repeated_failures_wait_longer_each_time(): void
    {
        $card = $this->card('https://example.com/gone');
        $card->update(['failure_count' => 3]);
        $this->queueResponse(new Response(404, ['Content-Type' => 'text/html'], ''));

        $this->runJob($card);

        $card->refresh();
        $this->assertSame(4, $card->failure_count);
        $this->assertTrue($card->next_attempt_at?->isAfter(CarbonImmutable::now()->addHours(1)));
    }

    public function test_it_makes_no_request_while_the_setting_is_off(): void
    {
        // The setting can be turned off after a job is queued; that must stop the request, not merely
        // hide its result.
        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, false);
        $card = $this->card('https://example.com/x');
        $this->queueHtml('<html><head><title>Never fetched</title></head></html>');

        $this->runJob($card);

        $this->assertSame([], $this->outboundRequests);
        $this->assertSame(LinkCardStatus::Pending, $card->fresh()?->status);
    }

    public function test_it_makes_no_request_when_another_worker_holds_the_lease(): void
    {
        $card = $this->card('https://example.com/busy');
        LinkCard::findOrFail($card->id)->claimFetch(120);
        $this->queueHtml('<html><head><title>Never fetched</title></head></html>');

        $this->runJob($card);

        $this->assertSame([], $this->outboundRequests);
        $this->assertSame(LinkCardStatus::Pending, $card->fresh()?->status);
    }

    public function test_it_only_calls_oembed_when_the_page_left_something_out(): void
    {
        // A page that described itself fully has already answered; a second request would be spent
        // learning nothing.
        $card = $this->card('https://example.com/complete');
        $this->queueHtml(<<<'HTML'
            <html><head>
            <meta property="og:title" content="Complete">
            <meta property="og:image" content="https://example.com/i.png">
            <link rel="alternate" type="application/json+oembed" href="https://example.com/oembed">
            </head></html>
            HTML);
        // Only the image fetch should follow; if oEmbed were called it would consume this instead.
        $this->queueBinary($this->png(), 'image/png');

        $this->runJob($card);

        $paths = array_map(fn ($request): string => $request->getUri()->getPath(), $this->outboundRequests);
        $this->assertNotContains('/oembed', $paths);
    }

    public function test_it_calls_oembed_to_fill_a_missing_title(): void
    {
        $card = $this->card('https://example.com/sparse');
        $this->queueHtml('<html><head><link rel="alternate" type="application/json+oembed" href="https://example.com/oembed"></head></html>');
        $this->queueJson(['version' => '1.0', 'title' => 'From oEmbed', 'provider_name' => 'Example']);

        $this->runJob($card);

        $this->assertSame('From oEmbed', $card->fresh()?->title);
    }

    public function test_a_missing_card_is_not_an_error(): void
    {
        $job = new FetchLinkCard(9999);

        $job->handle(
            $this->fakeFetcher(),
            new MetadataExtractor,
            new OembedClient($this->fakeFetcher()),
            $this->app->make(LinkCardImage::class),
            $this->app->make(LinkCardSettings::class),
        );

        $this->assertSame([], $this->outboundRequests);
    }

    public function test_the_job_is_unique_per_card(): void
    {
        // A link posted by many people at once arrives as many identical jobs.
        $this->assertSame((new FetchLinkCard(7))->uniqueId(), (new FetchLinkCard(7))->uniqueId());
        $this->assertNotSame((new FetchLinkCard(7))->uniqueId(), (new FetchLinkCard(8))->uniqueId());
    }

    private function card(string $url): LinkCard
    {
        return LinkCard::factory()->pending()->create([
            'url' => $url,
            'url_hash' => LinkUrl::hash($url),
        ]);
    }

    private function runJob(LinkCard $card): void
    {
        $fetcher = $this->fakeFetcher();

        (new FetchLinkCard($card->id))->handle(
            $fetcher,
            new MetadataExtractor,
            new OembedClient($fetcher),
            new LinkCardImage($fetcher, $this->app->make(FileUploader::class), $this->app->make(ImageManager::class)),
            $this->app->make(LinkCardSettings::class),
        );
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
