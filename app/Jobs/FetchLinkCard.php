<?php

declare(strict_types=1);

namespace App\Jobs;

use App\LinkCard\LinkCardImage;
use App\LinkCard\LinkCardSettings;
use App\LinkCard\MetadataExtractor;
use App\LinkCard\OembedClient;
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
 * Fetches one URL and fills in its card.
 *
 * This is the only job that talks to the network. It is deliberately hard to run twice at once and
 * hard to finish out of order, because both would happen otherwise: a link everyone shares at once
 * arrives as many identical jobs, and a job slow enough to lose its lease can come back after a
 * newer one has already answered.
 *
 *  - `ShouldBeUnique` on the card id collapses the duplicates a burst produces before they queue;
 *  - a conditional UPDATE claims the fetch, so of the jobs that do run, one proceeds and the rest
 *    return immediately (LinkCard::claimFetch);
 *  - every write back is fenced on the lease that claim returned, so a worker that overran cannot
 *    overwrite the result of the one that replaced it.
 *
 * `tries = 1`: retrying is expressed as a stored backoff on the row, not as a queue retry, so a URL
 * that is simply gone stops costing anything quickly and a transient failure is picked up on the
 * next view rather than immediately.
 */
class FetchLinkCard implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

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

        $lease = $card->claimFetch(self::LEASE_SECONDS);

        if ($lease === null) {
            return; // Another worker holds it.
        }

        $deadline = microtime(true) + (float) config('openpne.outbound.job_timeout');

        try {
            $card->completeFetch($lease, $this->fetched($card, $fetcher, $extractor, $oembed, $images, $deadline));
        } catch (OutboundException) {
            $card->completeFetch($lease, $this->failed($card));
        }
    }

    /**
     * The attributes describing a successful fetch.
     *
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
            // Reached, read, and had nothing to show. That is an answer, so it is cached like any
            // other rather than retried.
            return $this->failed($card);
        }

        $image = $metadata->imageUrl === null ? null : $images->import($metadata->imageUrl, $card->id, $deadline);

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
     * The attributes describing a failure, with the backoff that keeps it from being retried on
     * every view.
     *
     * @return array<string, mixed>
     */
    private function failed(LinkCard $card): array
    {
        $failures = $card->failure_count + 1;

        return [
            'status' => LinkCardStatus::Failed,
            'failure_count' => min($failures, 255),
            'fetched_at' => CarbonImmutable::now(),
            'expires_at' => null,
            'next_attempt_at' => LinkCard::backoffAfter($failures),
        ];
    }
}
