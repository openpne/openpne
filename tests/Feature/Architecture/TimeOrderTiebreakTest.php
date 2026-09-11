<?php

namespace Tests\Feature\Architecture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * A literal time-column order in app/ is followed, before the statement ends, by a unique-key
 * tiebreak (docs/internals/ordering.md, "Guards"). Queries that order by a variable, and orders
 * assembled across statements, are outside its sight and rely on their SQL pins.
 */
class TimeOrderTiebreakTest extends TestCase
{
    private const TIME_COLUMNS = 'created_at|updated_at|sort_at|bumped_at|last_comment_time|published_at|open_date|latest_message_at|latest_at|last_said|window_start|ym';

    /** Keys unique on their own, on any table: primary keys, a thread's number, the union's row id, a subquery's message id. */
    private const UNIQUE_KEYS = 'id|row_id|number|latest_id|latest_message_id';

    /** @var array<string, string> pivot columns that complete a composite primary key, by the file that orders on them */
    private const PIVOT_KEYS = [
        'app/Features/Friend/Queries/ListFriends.php' => 'friend_id',
        'app/Features/Friend/Queries/ListPendingRequests.php' => 'target_id|requester_id',
        'app/Features/Block/Queries/ListBlocks.php' => 'blocked_id',
        'app/Features/Group/Queries/ListPendingMembers.php' => 'member_id',
    ];

    /** @var array<string, string> orders on a column that is itself unique in that query, with the reason */
    private const UNIQUE_ON_ITS_OWN = [
        'app/Features/Diary/Queries/MemberDiaryMonthlyCounts.php' => 'ym is the group key',
        'app/Console/Commands/PublishHomeIssueCommand.php' => 'one issue per window (issue_date is unique)',
        'app/Console/Commands/RebuildHomeIssuesCommand.php' => 'one issue per window (issue_date is unique)',
    ];

    public function test_every_literal_time_order_ends_on_a_unique_key(): void
    {
        $root = base_path();
        $pattern = '/->(?:orderBy|orderByDesc|orderByPivot|reorder)\(\'(?:[a-z_]+\.)?(?:'.self::TIME_COLUMNS.')\'|->(?:latest|oldest)\((?:\)|\'[a-z_.]+\'\))/';
        $matches = 0;
        $untied = [];

        foreach ($this->phpFilesUnder($root.'/app') as $path) {
            $relative = substr($path, strlen($root) + 1);
            if (isset(self::UNIQUE_ON_ITS_OWN[$relative])) {
                continue;
            }
            $source = file_get_contents($path);

            foreach ($this->statementsMatching($pattern, $source) as [$line, $chain]) {
                $matches++;
                if (! $this->hasTiebreak($chain, $relative)) {
                    $untied[] = "{$relative}:{$line}";
                }
            }
        }

        $this->assertGreaterThan(20, $matches, 'the pattern found too few orders to be looking at the right code');
        $this->assertSame([], $untied);
    }

    /**
     * The chain runs from the order to the call that executes or caps it, so a tiebreak on another
     * arm of the same statement does not count for this one.
     *
     * @return list<array{int, string}>
     */
    private function statementsMatching(string $pattern, string $source): array
    {
        preg_match_all($pattern, $source, $found, PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($found[0] as [$text, $offset]) {
            $rest = substr($source, $offset + strlen($text));
            preg_match('/;|->(?:paginate|simplePaginate|cursorPaginate|get|first|firstOrFail|sole|pluck|limit|take|count|exists|chunk|lazy|cursor|value|toBase)\(/', $rest, $terminal, PREG_OFFSET_CAPTURE);
            $chain = $terminal === [] ? $rest : substr($rest, 0, $terminal[0][1]);
            $out[] = [substr_count(substr($source, 0, $offset), "\n") + 1, $chain];
        }

        return $out;
    }

    private function hasTiebreak(string $chain, string $relative): bool
    {
        if (preg_match('/->(?:orderBy|orderByDesc)\(\'(?:[a-z_]+\.)?(?:'.self::UNIQUE_KEYS.')\'/', $chain) === 1) {
            return true;
        }

        return isset(self::PIVOT_KEYS[$relative]) && preg_match('/->orderByPivot\(\'(?:'.self::PIVOT_KEYS[$relative].')\'/', $chain) === 1;
    }

    /** @return list<string> */
    private function phpFilesUnder(string $dir): array
    {
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
