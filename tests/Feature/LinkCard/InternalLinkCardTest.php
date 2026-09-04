<?php

declare(strict_types=1);

namespace Tests\Feature\LinkCard;

use App\Files\FileStorage;
use App\Files\FileUploader;
use App\Files\ImageCache;
use App\Files\ImageTransform;
use App\Jobs\FetchLinkCard;
use App\Jobs\SyncLinkCard;
use App\LinkCard\InternalCardRow;
use App\LinkCard\InternalUrl;
use App\LinkCard\LinkCardImage;
use App\LinkCard\LinkCardSettings;
use App\LinkCard\LinkCardSync;
use App\LinkCard\LinkUrl;
use App\LinkCard\MetadataExtractor;
use App\LinkCard\OembedClient;
use App\Models\Diary;
use App\Models\File;
use App\Models\LinkCard;
use App\Models\Member;
use App\Outbound\SafeHttpFetcher;
use App\Support\LinkCardStatus;
use App\Support\SnsSettingKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Tests\Concerns\FakesOutboundTransport;
use Tests\TestCase;

/**
 * The fetch runs against a real SafeHttpFetcher with only the socket and the resolver faked, so
 * "nothing went out" here is the absence of a request the fetcher would really have made.
 */
class InternalLinkCardTest extends TestCase
{
    use FakesOutboundTransport;
    use RefreshDatabase;

    private Member $author;

    private Diary $target;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://sns.example.com']);
        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, true);
        $this->author = Member::factory()->create();
        $this->target = Diary::factory()->for($this->author)->create(['title' => 'The diary being linked']);
        // A host the fetcher could really reach, so "no request" is never true merely because the
        // name would not resolve.
        $this->resolvesTo('sns.example.com', ['93.184.216.34']);
        $this->resolvesTo('example.com', ['93.184.216.34']);
    }

    public function test_a_body_linking_one_of_our_pages_gets_a_pointer_row(): void
    {
        Queue::fake();
        $diary = $this->bodyLinking($this->selfUrl());

        $this->sync($diary);

        $card = $diary->fresh()->linkCard;
        $this->assertSame(LinkCardStatus::Internal, $card->status);
        $this->assertSame('diary', $card->internal_context);
        $this->assertSame($this->target->id, $card->internal_record_id);
        $this->assertNotNull($diary->fresh()->link_card_synced_at);
        // Nothing about the destination is cached: the row is shared by every body that mentions the
        // URL, and what such a card says depends on who is reading it.
        $this->assertNull($card->title);
        $this->assertNull($card->description);
        $this->assertNull($card->site_name);
        $this->assertNull($card->image_file_id);
        $this->assertNull($card->expires_at);
        $this->assertNull($card->next_attempt_at);
        Queue::assertNotPushed(FetchLinkCard::class);
    }

    public function test_one_of_our_pages_that_names_no_record_is_examined_and_left_without_a_card(): void
    {
        Queue::fake();
        $diary = $this->bodyLinking('https://sns.example.com/diary/edit/'.$this->target->id);

        $this->sync($diary);

        $fresh = $diary->fresh();
        $this->assertNull($fresh->link_card_id);
        $this->assertNotNull($fresh->link_card_synced_at, 'The body must stop being re-parsed.');
        $this->assertSame(0, LinkCard::count(), 'A row that could only ever draw nothing was minted.');
        Queue::assertNotPushed(FetchLinkCard::class);
    }

    public function test_our_own_pages_are_resolved_while_the_setting_is_off(): void
    {
        // The switch governs fetching, and this card needs no fetch.
        Queue::fake();
        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, false);
        $diary = $this->bodyLinking($this->selfUrl());

        $this->sync($diary);

        $this->assertSame(LinkCardStatus::Internal, $diary->fresh()->linkCard->status);
        $this->assertNotNull($diary->fresh()->link_card_synced_at);
    }

    public function test_an_unresolvable_page_of_ours_is_examined_while_the_setting_is_off(): void
    {
        Queue::fake();
        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, false);
        $diary = $this->bodyLinking('https://sns.example.com/diary');

        $this->sync($diary);

        $fresh = $diary->fresh();
        $this->assertNull($fresh->link_card_id);
        $this->assertNotNull($fresh->link_card_synced_at, 'Whether this URL has a card does not depend on the setting.');
    }

    public function test_a_body_whose_first_link_is_external_is_left_unexamined_while_the_setting_is_off(): void
    {
        // The second URL is deliberately one of ours: a card is the first URL, and marking here would
        // cost the body its card forever, since the marker is written once and nothing revisits it
        // when the setting returns.
        Queue::fake();
        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, false);
        $diary = $this->bodyLinking('https://example.com/article', $this->selfUrl());

        $this->sync($diary);

        $fresh = $diary->fresh();
        $this->assertNull($fresh->link_card_id);
        $this->assertNull($fresh->link_card_synced_at);
        $this->assertSame(0, LinkCard::count());
    }

    public function test_a_body_with_no_url_is_left_unexamined_while_the_setting_is_off(): void
    {
        Queue::fake();
        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, false);
        $diary = $this->bodyLinking();

        $this->sync($diary);

        $this->assertNull($diary->fresh()->link_card_synced_at);
    }

    public function test_an_external_link_still_takes_the_fetch_path(): void
    {
        Queue::fake();
        $diary = $this->bodyLinking('https://example.com/article');

        $this->sync($diary);

        $card = $diary->fresh()->linkCard;
        $this->assertSame(LinkCardStatus::Pending, $card->status);
        $this->assertNull($card->internal_context);
        Queue::assertPushed(FetchLinkCard::class);
    }

    public function test_a_row_fetched_before_this_existed_is_converted_and_loses_its_picture(): void
    {
        Queue::fake();
        $card = $this->fetchedCard($this->selfUrl());
        $image = $card->image;
        $cacheKey = $this->cachedThumbnailOf($image);
        $diary = $this->bodyLinking($this->selfUrl());

        $this->sync($diary);

        $card->refresh();
        $this->assertSame(LinkCardStatus::Internal, $card->status);
        $this->assertSame($this->target->id, $card->internal_record_id);
        $this->assertNull($card->title);
        $this->assertNull($card->image_file_id);
        $this->assertNull($card->fetched_at);
        $this->assertSame(0, $card->failure_count);
        $this->assertDatabaseMissing('files', ['id' => $image->id]);
        $this->assertFalse($this->app->make(FileStorage::class)->exists($image), 'The stored bytes outlived the card.');
        $this->assertFalse(Storage::disk(config('openpne.images.cache_disk'))->exists($cacheKey), 'A cached thumbnail outlived the file.');
    }

    public function test_a_picture_a_fetch_stored_while_this_ran_goes_with_the_rest(): void
    {
        // The window the row's own column cannot close: a fetch in flight stores its picture around
        // the conversion, so the card owns two files while the row names at most one.
        Queue::fake();
        $card = $this->fetchedCard($this->selfUrl());
        $named = $card->image;
        $inFlight = $this->storedImageFor($card);
        $cacheKey = $this->cachedThumbnailOf($inFlight);
        $elsewhere = $this->fetchedCard('https://example.com/article');
        $diary = $this->bodyLinking($this->selfUrl());

        $this->sync($diary);

        $this->assertDatabaseMissing('files', ['id' => $named->id]);
        $this->assertNull(File::find($inFlight->id), 'A picture stored around the conversion was left behind.');
        $this->assertFalse(Storage::disk(config('openpne.images.cache_disk'))->exists($cacheKey), 'A cached thumbnail outlived the file.');
        $this->assertNotNull(File::find($elsewhere->image_file_id), "Another card's picture was deleted with this one's.");
    }

    public function test_a_row_of_ours_that_names_no_record_is_repaired_where_one_already_exists(): void
    {
        // No row is minted for such an address, but one already there is shared by every body that
        // mentions it — left alone it goes on drawing the login screen under all of them.
        Queue::fake();
        $url = 'https://sns.example.com/diary';
        $card = $this->fetchedCard($url);
        $image = $card->image;
        $diary = $this->bodyLinking($url);

        $this->sync($diary);

        $card->refresh();
        $this->assertSame(LinkCardStatus::Internal, $card->status);
        $this->assertNull($card->title, 'The card of a login screen survived.');
        $this->assertNull($card->internal_context, 'A row of ours naming no record must hold no pointer.');
        $this->assertDatabaseMissing('files', ['id' => $image->id]);
        $this->assertSame($card->id, $diary->fresh()->link_card_id);
    }

    public function test_a_row_that_points_at_the_record_but_holds_more_is_still_cleared(): void
    {
        // Status and pointer agree, so the pointer alone reads as "already converted" — and the
        // metadata beside them is exactly what a row shared by every body must never carry.
        $card = $this->internalRow();
        $card->forceFill([
            'title' => 'Log in',
            'description' => 'Sign in to continue',
            'image_file_id' => $this->storedImageFor($card)->id,
            'fetched_at' => CarbonImmutable::now(),
            'failure_count' => 3,
        ])->save();
        $image = $card->fresh()->image;

        InternalCardRow::convert($card->fresh(), InternalUrl::of($this->selfUrl()));

        $card->refresh();
        $this->assertNull($card->title);
        $this->assertNull($card->description);
        $this->assertNull($card->image_file_id);
        $this->assertNull($card->fetched_at);
        $this->assertSame(0, $card->failure_count);
        $this->assertDatabaseMissing('files', ['id' => $image->id]);
    }

    public function test_an_internal_row_is_never_claimed_for_a_fetch(): void
    {
        $card = $this->internalRow();

        $this->assertFalse($card->isDueForFetch());
        $this->assertNull($card->claimFetch(120), 'The claim took a lease on a row that must never be fetched.');
        $this->assertNull($card->fresh()->next_attempt_at, 'The claim wrote a lease into a row whose schedule is null by invariant.');
    }

    public function test_the_fetch_job_converts_one_of_our_urls_instead_of_requesting_it(): void
    {
        // The stimulus has to land: a job that returned early for some other reason would satisfy
        // "nothing went out" on its own, so the conversion is asserted alongside it.
        $card = $this->fetchedCard($this->selfUrl());

        $this->runFetch($card);

        $this->assertSame(LinkCardStatus::Internal, $card->fresh()->status);
        $this->assertSame($this->target->id, $card->fresh()->internal_record_id);
        $this->assertSame([], $this->outboundRequests, 'The app requested one of its own pages.');
    }

    public function test_a_url_of_ours_that_resolves_to_nothing_is_converted_without_a_pointer(): void
    {
        $card = $this->fetchedCard('https://sns.example.com/diary');

        $this->runFetch($card);

        $card->refresh();
        $this->assertSame(LinkCardStatus::Internal, $card->status);
        $this->assertNull($card->internal_context, 'A row of ours naming no record must hold no pointer.');
        $this->assertNull($card->title, 'The card of a login screen survived.');
        $this->assertSame([], $this->outboundRequests);
    }

    public function test_a_row_marked_internal_is_refused_by_the_claim_even_where_the_url_no_longer_reads_as_ours(): void
    {
        // Reached only when the URL test does not fire (the site renamed since the row was written);
        // nothing went out is not self-evidence here, which the sibling below shows by making the
        // request over an external row.
        $card = $this->internalRow();
        config(['app.url' => 'https://elsewhere.example.com']);

        $this->runFetch($card);

        $this->assertSame(LinkCardStatus::Internal, $card->fresh()->status);
        $this->assertNull($card->fresh()->next_attempt_at, 'A lease was taken on an internal row.');
        $this->assertSame([], $this->outboundRequests);
    }

    public function test_an_external_url_is_still_fetched(): void
    {
        // The other direction: none of the guards above may swallow the ordinary case.
        $card = LinkCard::factory()->pending()->create([
            'url' => 'https://example.com/article',
            'url_hash' => LinkUrl::hash('https://example.com/article'),
        ]);
        $this->queueHtml('<html><head><meta property="og:title" content="An article"></head></html>');

        $this->runFetch($card);

        $this->assertSame(LinkCardStatus::Ok, $card->fresh()->status);
        $this->assertCount(1, $this->outboundRequests);
    }

    /**
     * The read trigger, end to end: the page a stale card sits on repairs it.
     *
     * @see LinkCardSync
     */
    public function test_opening_a_page_repairs_a_stale_row_of_ours_without_a_request(): void
    {
        $this->app->instance(SafeHttpFetcher::class, $this->fakeFetcher());
        $card = $this->fetchedCard($this->selfUrl(), ['expires_at' => CarbonImmutable::now()->subDay()]);
        $diary = $this->bodyLinking($this->selfUrl());
        $diary->forceFill(['link_card_id' => $card->id, 'link_card_synced_at' => CarbonImmutable::now()])->save();

        $this->actingAs($this->author)->get("/diary/{$diary->id}")->assertOk();

        $this->assertSame(LinkCardStatus::Internal, $card->fresh()->status);
        $this->assertSame([], $this->outboundRequests);
    }

    private function selfUrl(): string
    {
        return 'https://sns.example.com/diary/'.$this->target->id;
    }

    /** A diary whose body mentions $urls, in that order. */
    private function bodyLinking(string ...$urls): Diary
    {
        return Diary::factory()->for($this->author)->create([
            'title' => 'A body with links',
            'body' => 'See '.implode(' and then ', $urls),
        ]);
    }

    private function sync(Diary $diary): void
    {
        (new SyncLinkCard(Diary::class, $diary->id))->handle($this->app->make(LinkCardSettings::class));
    }

    private function runFetch(LinkCard $card): void
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

    /** A card the fetch job filled in before this app knew its own host, picture and all. */
    private function fetchedCard(string $url, array $attributes = []): LinkCard
    {
        $card = LinkCard::factory()->create($attributes + [
            'url' => $url,
            'url_hash' => LinkUrl::hash($url),
            'title' => 'Log in',
            'status' => LinkCardStatus::Ok,
        ]);

        $card->update(['image_file_id' => $this->storedImageFor($card)->id]);

        return $card->refresh();
    }

    private function internalRow(): LinkCard
    {
        $url = $this->selfUrl();

        return LinkCard::factory()->create([
            'url' => $url,
            'url_hash' => LinkUrl::hash($url),
            'status' => LinkCardStatus::Internal,
            'title' => null,
            'description' => null,
            'site_name' => null,
            'fetched_at' => null,
            'expires_at' => null,
            'next_attempt_at' => null,
            'internal_context' => 'diary',
            'internal_record_id' => $this->target->id,
        ]);
    }

    /** A real PNG on the storage seam, as the fetch job would have left one. */
    private function storedImageFor(LinkCard $card): File
    {
        $file = File::factory()->create([
            'type' => 'image/png',
            'related_entity_type' => 'link_card',
            'related_entity_id' => $card->id,
            'width' => 40,
            'height' => 40,
        ]);

        $image = imagecreatetruecolor(40, 40);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);
        $this->app->make(FileStorage::class)->writeStream($file, $stream);

        return $file;
    }

    /** Generate one cached thumbnail of $file and return the key it was cached under. */
    private function cachedThumbnailOf(File $file): string
    {
        $transform = ImageTransform::fromGeometry('w120_h120_sq');
        $this->app->make(ImageCache::class)->bytes($file, $transform, 'png');
        $key = $transform->cacheKey($file->name, 'png');

        $this->assertTrue(Storage::disk(config('openpne.images.cache_disk'))->exists($key), 'The thumbnail was never cached, so its removal proves nothing.');

        return $key;
    }
}
