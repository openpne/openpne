<?php

declare(strict_types=1);

namespace App\Jobs;

use App\LinkCard\InternalCardRow;
use App\LinkCard\InternalUrl;
use App\LinkCard\LinkCardImage;
use App\LinkCard\LinkCardSettings;
use App\LinkCard\MetadataExtractor;
use App\LinkCard\OembedClient;
use App\Models\File;
use App\Models\LinkCard;
use App\Outbound\OutboundException;
use App\Outbound\SafeHttpFetcher;
use App\Support\LinkCardStatus;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The only job that talks to the network. How duplicates are collapsed, how the fetch is claimed and
 * how a write back is fenced are in [link-cards.md](../../docs/internals/link-cards.md) § Two
 * workers, one URL.
 */
class FetchLinkCard implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** A retry is a stored backoff on the row, not a queue retry. */
    public int $tries = 1;

    /** Longer than the job's own budget, so the lease outlives the work it guards. */
    private const LEASE_SECONDS = 120;

    /** Only after a burst has settled is a second attempt at the same URL worth queueing. */
    public int $uniqueFor = 300;

    public function __construct(public readonly int $linkCardId) {}

    public function uniqueId(): string
    {
        return (string) $this->linkCardId;
    }

    public function handle(
        SafeHttpFetcher $fetcher,
        MetadataExtractor $extractor,
        OembedClient $oembed,
        LinkCardImage $images,
        LinkCardSettings $settings,
    ): void {
        // Re-checked here, not only where the job was queued: the setting can be turned off while
        // work is already on the queue, and that must stop the outbound request rather than merely
        // hide its result.
        if (! $settings->enabled()) {
            return;
        }

        $card = LinkCard::find($this->linkCardId);

        if ($card === null) {
            return;
        }

        // Decided from the URL, before the lease and before the row's own status is trusted, so this
        // app never requests its own pages whatever state a row is in.
        $link = InternalUrl::of($card->url);

        if ($link->isSelfHosted) {
            InternalCardRow::convert($card, $link);

            return;
        }

        // The claim's own `status != internal` condition answers the row this check cannot: one
        // already marked internal whose URL has stopped reading as ours after a host was renamed.
        $lease = $card->claimFetch(self::LEASE_SECONDS);

        if ($lease === null) {
            return; // Another worker holds it.
        }

        $deadline = microtime(true) + (float) config('openpne.outbound.job_timeout');
        $imported = null;

        try {
            $attributes = $this->fetched($card, $fetcher, $extractor, $oembed, $images, $deadline, $imported);
        } catch (OutboundException) {
            $attributes = $this->failed($card);
        }

        if (! $card->completeFetch($lease, $attributes) && $imported !== null) {
            // The lease moved on while this was fetching, so the row keeps what the newer worker
            // wrote and the image downloaded here is referenced by nothing.
            $imported->delete();
        }
    }

    /**
     * The attributes describing a successful fetch.
     *
     * @param  File|null  $imported  Set to the stored image, so the caller can remove it if the
     *                               result turns out to be unwritable.
     * @return array<string, mixed>
     *
     * @throws OutboundException
     */
    private function fetched(
        LinkCard $card,
        SafeHttpFetcher $fetcher,
        MetadataExtractor $extractor,
        OembedClient $oembed,
        LinkCardImage $images,
        float $deadline,
        ?File &$imported,
    ): array {
        $response = $fetcher->get($card->url, (int) config('openpne.outbound.max_html_bytes'), $deadline);

        if ($response->status !== 200 || ! str_contains($response->mediaType(), 'html')) {
            return $this->failed($card);
        }

        $metadata = $extractor->extract($response->body, $response->charset(), $response->url);

        // The second request is only worth making when the page left something out; a page that
        // described itself fully has already answered.
        if ($metadata->oembedUrl !== null && (! $metadata->isUsable() || $metadata->imageUrl === null)) {
            $metadata = $metadata->completedWith($oembed->fetch($metadata->oembedUrl, $deadline));
        }

        if (! $metadata->isUsable()) {
            // Reached and read but with nothing to show: recorded as a failure like any other, so
            // the same backoff decides when it is asked again.
            return $this->failed($card);
        }

        $image = $metadata->imageUrl === null ? null : $images->import($metadata->imageUrl, $card->id, $deadline);
        $imported = $image['file'] ?? null;

        return [
            'status' => LinkCardStatus::Ok,
            'title' => $metadata->title,
            'description' => $metadata->description,
            'site_name' => $metadata->siteName,
            'author_name' => $metadata->authorName,
            'image_file_id' => $image['file']->id ?? null,
            'image_width' => $image['width'] ?? null,
            'image_height' => $image['height'] ?? null,
            'failure_count' => 0,
            'fetched_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addDays(7),
            // Released, so a refresh after expiry can claim it.
            'next_attempt_at' => null,
        ];
    }

    /**
     * A card that already renders is not demoted by a failure; only the schedule moves
     * ([link-cards.md](../../docs/internals/link-cards.md) § Two workers, one URL).
     *
     * @return array<string, mixed>
     */
    private function failed(LinkCard $card): array
    {
        $failures = $card->failure_count + 1;

        $schedule = [
            'failure_count' => min($failures, 255),
            'fetched_at' => CarbonImmutable::now(),
            'next_attempt_at' => LinkCard::backoffAfter($failures),
        ];

        if ($card->isRenderable()) {
            // expires_at stays in the past, so once the backoff elapses this is due again.
            return $schedule;
        }

        return $schedule + ['status' => LinkCardStatus::Failed, 'expires_at' => null];
    }
}
