<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Features\Timeline\HashtagParser;
use App\Models\TimelinePost;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * See docs/internals/timeline.md "Bodies the parser never saw".
 */
class BackfillTimelineHashtagsCommand extends Command
{
    protected $signature = 'openpne:timeline-backfill-hashtags';

    protected $description = 'Re-index every timeline post\'s #hashtags from its body';

    public function handle(): int
    {
        $posts = 0;
        $tags = 0;

        TimelinePost::query()
            ->with('mentions')
            ->chunkById(200, function (Collection $chunk) use (&$posts, &$tags): void {
                foreach ($chunk as $post) {
                    $posts++;
                    $tags += $this->reindex($post);
                }
            });

        $this->info("Indexed {$tags} hashtag(s) across {$posts} post(s).");

        return self::SUCCESS;
    }

    private function reindex(TimelinePost $post): int
    {
        $mentions = $post->mentions
            ->map(fn ($mention): array => ['offset' => $mention->offset, 'length' => $mention->length])
            ->all();

        return DB::transaction(function () use ($post, $mentions): int {
            $post->tags()->delete();

            $tags = HashtagParser::parse($post->body, $mentions);
            $post->tags()->createMany($tags);

            return count($tags);
        });
    }
}
