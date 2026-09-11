<?php

namespace Tests\Feature\Architecture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * A literal time-column order in app/ is followed, before the call that executes or caps it, by a
 * unique-key tiebreak (docs/internals/ordering.md, "Guards"). Queries that order by a variable, and
 * orders assembled across statements, are outside its sight and rely on their SQL pins.
 */
class TimeOrderTiebreakTest extends TestCase
{
    private const TIME_COLUMNS = 'created_at|updated_at|sort_at|bumped_at|last_comment_time|published_at|open_date|latest_message_at|latest_at|last_said|window_start|ym';

    /** A primary key, bare or table-qualified, is the one tiebreak unique on any table; `min(id)` is its aggregate over a group. */
    private const PRIMARY_KEY = '/->(?:orderBy|orderByDesc)\(\'(?:[a-z_]+\.)?id\'|->orderByRaw\(\'min\(id\)\'\)/';

    /**
     * Tails that are unique only in one query, by file or file:line: a pivot's other key column, the
     * union's `(role, row_id)`, the conversation heads' counterpart after a shared latest message.
     *
     * @var array<string, string>
     */
    private const COMPOSITE_TAILS = [
        'app/Features/Friend/Queries/ListFriends.php' => '/->orderByPivot\(\'friend_id\'/',
        'app/Features/Friend/Queries/ListPendingRequests.php:19' => '/->orderByPivot\(\'target_id\'/',
        'app/Features/Friend/Queries/ListPendingRequests.php:22' => '/->orderByPivot\(\'requester_id\'/',
        'app/Features/Block/Queries/ListBlocks.php' => '/->orderByPivot\(\'blocked_id\'/',
        'app/Features/Group/Queries/ListPendingMembers.php' => '/->orderByPivot\(\'member_id\'/',
        'app/Features/DirectMessage/Queries/ListDirectMessages.php' => '/->orderByDesc\(\'role\'\)->orderByDesc\(\'row_id\'\)/',
        'app/Features/DirectMessage/Queries/ShowDirectMessage.php' => '/->orderBy\(\'role\',[^)]*\)\s*->orderBy\(\'row_id\'/',
        'app/Features/DirectMessage/Queries/ConversationList.php' => '/->orderByDesc\(\'heads\.counterpart_id\'\)/',
    ];

    /** @var array<string, string> orders whose column is unique in that one query, with the reason, by file:line */
    private const UNIQUE_ON_ITS_OWN = [
        'app/Features/Diary/Queries/MemberDiaryMonthlyCounts.php:30' => 'ym is the group key',
        'app/Console/Commands/PublishHomeIssueCommand.php:136' => 'windows do not overlap (windowIsClear), so one issue per window_start',
        'app/Console/Commands/RebuildHomeIssuesCommand.php:56' => 'windows do not overlap (windowIsClear), so one issue per window_start',
        'app/Console/Commands/RebuildHomeIssuesCommand.php:153' => 'windows do not overlap (windowIsClear), so one issue per window_start',
    ];

    public function test_every_literal_time_order_ends_on_a_unique_key(): void
    {
        $root = base_path();
        $time = '(?:[a-z_]+\.)?(?:'.self::TIME_COLUMNS.')';
        $pattern = '/->(?:orderBy|orderByDesc|orderByPivot|reorder)\(\''.$time.'\'|->orderByRaw\(\'[^\']*\b'.$time.'\b[^\']*\'|->(?:latest|oldest)\((?:\)|\''.$time.'\'\))/';
        $matches = 0;
        $untied = [];
        $unused = [...array_keys(self::COMPOSITE_TAILS), ...array_keys(self::UNIQUE_ON_ITS_OWN)];

        foreach ($this->phpFilesUnder($root.'/app') as $path) {
            $relative = substr($path, strlen($root) + 1);
            $source = file_get_contents($path);

            foreach ($this->statementsMatching($pattern, $source) as [$line, $chain]) {
                $matches++;
                $key = $this->allowanceFor($relative, $line);
                if ($key !== null) {
                    $unused = array_values(array_diff($unused, [$key]));
                }
                if ($key !== null && isset(self::UNIQUE_ON_ITS_OWN[$key])) {
                    continue;
                }
                if (preg_match(self::PRIMARY_KEY, $chain) === 1 || ($key !== null && preg_match(self::COMPOSITE_TAILS[$key], $chain) === 1)) {
                    continue;
                }
                $untied[] = "{$relative}:{$line}";
            }
        }

        $this->assertGreaterThan(20, $matches, 'the pattern found too few orders to be looking at the right code');
        $this->assertSame([], $untied);
        // An allowance nothing used any more is a licence waiting for the wrong query.
        $this->assertSame([], $unused);
    }

    /** The allowance that applies to this order: its line first, then its file. */
    private function allowanceFor(string $relative, int $line): ?string
    {
        foreach (["{$relative}:{$line}", $relative] as $key) {
            if (isset(self::COMPOSITE_TAILS[$key]) || isset(self::UNIQUE_ON_ITS_OWN[$key])) {
                return $key;
            }
        }

        return null;
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
