<?php

namespace Tests\Unit\Features\Group;

use App\Features\Group\GroupPostTitle;
use App\Models\CommunityEvent;
use App\Models\CommunityTopic;
use PHPUnit\Framework\TestCase;

class GroupPostTitleTest extends TestCase
{
    public function test_short_name_keeps_its_text_and_appends_the_count_with_no_space(): void
    {
        $this->assertSame('Hello(3)', GroupPostTitle::withCount($this->topic('Hello', 3)));
    }

    public function test_missing_count_renders_as_zero(): void
    {
        $topic = new CommunityTopic;
        $topic->name = 'No count loaded';

        $this->assertSame('No count loaded(0)', GroupPostTitle::withCount($topic));
    }

    public function test_long_ascii_name_is_truncated_to_36_without_an_ellipsis(): void
    {
        $label = GroupPostTitle::withCount($this->topic(str_repeat('a', 50), 0));

        $this->assertSame(str_repeat('a', 36).'(0)', $label);
    }

    public function test_full_width_name_is_truncated_by_display_width(): void
    {
        // 20 full-width characters span display width 40; OpenPNE 3 truncates to width 36 = 18 characters.
        $label = GroupPostTitle::withCount($this->topic(str_repeat('あ', 20), 1));

        $this->assertSame(str_repeat('あ', 18).'(1)', $label);
    }

    public function test_accepts_an_event(): void
    {
        $event = new CommunityEvent;
        $event->name = 'Meetup';
        $event->comments_count = 2;

        $this->assertSame('Meetup(2)', GroupPostTitle::withCount($event));
    }

    private function topic(string $name, int $count): CommunityTopic
    {
        $topic = new CommunityTopic;
        $topic->name = $name;
        $topic->comments_count = $count;

        return $topic;
    }
}
