<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Pins that a write to a memoised relation clears the memo.
 *
 * `ViewerRelations` holds answers about blocks, friendships and group roles that were true when a
 * page read them. Forgetting the flush fails **open** — a request that changes one of these and then
 * renders is answered from before its own write — and nothing about that is visible at the call
 * site, so wiring it by hand alone would rot silently. This is the guard that says the wiring is
 * complete.
 *
 * The detection is deliberately coarse: any file naming one of the three tables (or the membership
 * model) alongside a write verb has to flush, or be listed below with a reason. A file that trips
 * this and writes none of the three is an entry in that list; a file that trips it and does write
 * one is a bug this test is for.
 */
class ViewerRelationsFlushTest extends TestCase
{
    /** What names one of the memoised relations. */
    private const RELATIONS = ["'member_blocks'", "'friendships'", "'group_members'", 'GroupMember::', 'members()->forceCreate'];

    private const WRITES = ['insert', '->update(', '->delete()', 'forceCreate(', 'upsert(', '->save()'];

    /**
     * Files that name a relation beside a write verb but write no membership, friendship or block.
     *
     * Each is here because what it writes is a *column on* one of these tables that no rule memoised
     * here reads, or because it only reads the table while writing something else.
     */
    private const WRITES_SOMETHING_ELSE = [
        // The talk read cursor and the mute flag: columns on group_members, never the membership or
        // the role, and both are written on the render path a memo is warmed for.
        'Features/GroupTalk/TalkReadCursor.php',
        'Features/GroupTalk/Actions/SetTalkMute.php',
        // Reads group_members to decide who a mention may name; writes mention rows.
        'Features/Timeline/Actions/ResolveMentions.php',
        // The relation definitions themselves.
        'Models/Member.php',
    ];

    /**
     * The upgrade tool, which has no reader and no request: it imports OpenPNE 3 rows from the
     * command line, and nothing it writes is being read through a memo at the time.
     */
    private const EXCLUDED_DIRS = ['Upgrade/'];

    public function test_a_write_to_a_memoised_relation_clears_the_memo(): void
    {
        $writers = [];
        $offenders = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            $relative = str_replace(app_path().'/', '', $file);

            foreach (self::EXCLUDED_DIRS as $excluded) {
                if (str_starts_with($relative, $excluded)) {
                    continue 2;
                }
            }

            $contents = (string) file_get_contents($file);
            $names = array_filter(self::RELATIONS, fn (string $marker): bool => str_contains($contents, $marker));
            $writes = array_filter(self::WRITES, fn (string $verb): bool => str_contains($contents, $verb));

            if ($names === [] || $writes === [] || in_array($relative, self::WRITES_SOMETHING_ELSE, true)) {
                continue;
            }

            $writers[] = $relative;

            if (! str_contains($contents, 'ViewerRelations::flush(')) {
                $offenders[] = $relative;
            }
        }

        // The scan must have found the writers at all: a marker list that stopped matching would let
        // this pass over an empty set.
        $this->assertGreaterThan(10, count($writers), 'The scan found almost no relation writers, so it is not testing anything.');
        $this->assertSame([], $offenders, 'These write a block, a friendship or a membership without clearing what pages have memoised about it: '.implode(', ', $offenders));
    }

    /** @return list<string> absolute paths of every .php file under $dir, recursively, sorted */
    private function phpFilesUnder(string $dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
