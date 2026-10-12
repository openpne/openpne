<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

/**
 * The views listed below are the frozen set of hand-written parts frames (each transcribes an
 * OpenPNE 3 partial that hand-writes it); new page parts use <x-classic.parts>, whose nesting cannot
 * drift. Matched on the file's text, not parsed markup, so a Blade directive between the divs cannot
 * hide one.
 */
class ClassicPartsFrameGuardTest extends TestCase
{
    public function test_hand_written_two_layer_frames_are_a_locked_set(): void
    {
        $this->assertSame([
            // default/_activityBox.php — op_include_box with a `class` option and a moreInfo footer
            'components/gadget/activity-box',
            'components/gadget/all-member-activity-box',
            // opDiaryPlugin diaryComment/_history.php and diary/_{diary,friendDiary,memberDiary,myDiary}List.php
            'components/gadget/diary-comment-history',
            'components/gadget/diary-friend-list',
            'components/gadget/diary-list',
            'components/gadget/diary-member-list',
            'components/gadget/diary-my-list',
            // opCommunityTopicPlugin communityEvent/_eventComment{,Sns}ListBox.php and the topic pair;
            // the Sns variants take a second class from their _parts*RecentList.php body partial
            'components/gadget/recent-group-event-comment',
            'components/gadget/recent-group-event-comment-sns',
            'components/gadget/recent-group-topic-comment',
            'components/gadget/recent-group-topic-comment-sns',
            // opTimelinePlugin _timelineAll.php / _timelineFriend.php / _timelineProfile.php
            'components/gadget/timeline-all',
            'components/gadget/timeline-friend',
            'components/gadget/timeline-profile',
            // opMessagePlugin deleteListConfirmSuccess.php / deleteConfirmSuccess.php / showSuccess.php
            'message/bulk_purge_confirm',
            'message/purge_confirm',
            'message/show',
        ], $this->viewsContaining('class="dparts'));
    }

    public function test_hand_written_single_layer_frames_are_a_locked_set(): void
    {
        // A single kind drops the inner div, so `class="parts ` is the shape's fingerprint (and
        // does not match the `.partsHeading` every frame emits).
        $this->assertSame([
            'components/gadget/birthday-box', // member/_birthdayBox.php
            'components/message/sidemenu',    // opMessagePlugin message/_sidemenu.php
        ], $this->viewsContaining('class="parts '));
    }

    public function test_frames_keeping_parts_under_a_theme_are_a_locked_set(): void
    {
        // Marks per view, so dropping one of a view's several frames shows (docs/internals/classic-compatibility.md, "Themes").
        $this->assertSame([
            'components/diary/sidemenu' => 3,
            'diary/comment/delete' => 1,
            'diary/comment/history' => 1,
            'diary/delete' => 1,
            'diary/edit' => 1,
            'diary/feed' => 2,
            'diary/list' => 1,
            'diary/show' => 2,
            'group-event/index' => 1,
            'group-event/show' => 2,
            'group-topic/index' => 1,
            'group-topic/show' => 2,
            'group/manage' => 1,
            'group/show' => 1,
            'message/list' => 1,
            'timeline/index' => 1,
            'timeline/member' => 1,
            'timeline/show' => 1,
            'timeline/tag' => 1,
        ], $this->occurrencesByView(':hand-written="true"'));
    }

    /** @return list<string> */
    private function viewsContaining(string $needle): array
    {
        return array_keys($this->occurrencesByView($needle));
    }

    /** @return array<string, int> view => occurrences, for the views holding at least one */
    private function occurrencesByView(string $needle): array
    {
        $views = dirname(__DIR__, 3).'/resources/views';
        $found = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($views, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $count = substr_count((string) file_get_contents($file->getPathname()), $needle);
            if ($count > 0) {
                $found[str_replace([$views.'/', '.blade.php'], '', $file->getPathname())] = $count;
            }
        }
        ksort($found);

        return $found;
    }
}
