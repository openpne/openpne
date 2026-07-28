<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

/**
 * Hand-writing the frame is how its nesting drifts: a `.partsHeading` emitted as a sibling of
 * `.parts` lands outside the box every skin draws, and no per-screen assertion notices. New page
 * parts therefore use <x-classic.parts>. The views below predate it and each transcribes an
 * OpenPNE 3 partial that hand-writes the frame too, so the set is frozen rather than rewritten —
 * matching on the file, not the markup, so a Blade directive between the divs cannot hide one.
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
            'components/gadget/recent-community-event-comment',
            'components/gadget/recent-community-event-comment-sns',
            'components/gadget/recent-community-topic-comment',
            'components/gadget/recent-community-topic-comment-sns',
            // opTimelinePlugin _timelineAll.php / _timelineFriend.php / _timelineProfile.php
            'components/gadget/timeline-all',
            'components/gadget/timeline-friend',
            'components/gadget/timeline-profile',
            // opMessagePlugin deleteListConfirmSuccess.php / listSuccess.php / deleteConfirmSuccess.php / showSuccess.php
            'message/bulk_purge_confirm',
            'message/list',
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

    /** @return list<string> */
    private function viewsContaining(string $needle): array
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
            if (str_contains((string) file_get_contents($file->getPathname()), $needle)) {
                $found[] = str_replace([$views.'/', '.blade.php'], '', $file->getPathname());
            }
        }
        sort($found);

        return $found;
    }
}
