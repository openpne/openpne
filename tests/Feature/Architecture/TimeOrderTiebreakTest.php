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
     * Tails that are unique only in one query, by file: a pivot's other key column, the union's
     * `(role, row_id)`, the conversation heads' counterpart after a shared latest message.
     *
     * @var array<string, string>
     */
    private const COMPOSITE_TAILS = [
        'app/Features/Friend/Queries/ListFriends.php' => '/->orderByPivot\(\'friend_id\'/',
        'app/Features/Friend/Queries/ListPendingRequests.php' => '/->orderByPivot\(\'(?:target_id|requester_id)\'/',
        'app/Features/Block/Queries/ListBlocks.php' => '/->orderByPivot\(\'blocked_id\'/',
        'app/Features/Group/Queries/ListPendingMembers.php' => '/->orderByPivot\(\'member_id\'/',
        'app/Features/DirectMessage/Queries/ListDirectMessages.php' => '/->orderByDesc\(\'role\'\)->orderByDesc\(\'row_id\'\)/',
        'app/Features/DirectMessage/Queries/ShowDirectMessage.php' => '/->orderBy\(\'role\', \$direction\)->orderBy\(\'row_id\', \$direction\)/',
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

        foreach ($this->phpFilesUnder($root.'/app') as $path) {
            $relative = substr($path, strlen($root) + 1);
            $source = file_get_contents($path);

            foreach ($this->statementsMatching($pattern, $source) as [$line, $chain]) {
                $matches++;
                if (isset(self::UNIQUE_ON_ITS_OWN["{$relative}:{$line}"]) || $this->hasTiebreak($chain, $relative)) {
                    continue;
                }
                $untied[] = "{$relative}:{$line}";
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
        if (preg_match(self::PRIMARY_KEY, $chain) === 1) {
            return true;
        }

        return isset(self::COMPOSITE_TAILS[$relative]) && preg_match(self::COMPOSITE_TAILS[$relative], $chain) === 1;
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
