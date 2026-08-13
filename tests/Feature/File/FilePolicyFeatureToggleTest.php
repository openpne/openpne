<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Files\FileStorage;
use App\Models\AdminUser;
use App\Models\BannerImage;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DirectMessage;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Feature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Files are fetched by URL, so no page mediates them: switching a unit off has to stop its bytes at
 * the policy or its images stay readable while every screen around them is gone
 * (docs/internals/feature-modules.md, Key invariant 2).
 */
class FilePolicyFeatureToggleTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, Feature}> owner morph alias => the unit that owns it */
    public static function ownerUnits(): array
    {
        return [
            'diary' => ['diary', Feature::Diary],
            'diary comment' => ['diaryComment', Feature::Diary],
            'direct message attachment' => ['directMessage', Feature::DirectMessage],
            'timeline post' => ['timelinePost', Feature::Timeline],
            'community top image (legacy alias)' => ['community', Feature::Group],
            'group top image (write alias)' => ['group', Feature::Group],
            'community topic' => ['communityTopic', Feature::GroupTopic],
            'community topic comment' => ['communityTopicComment', Feature::GroupTopic],
            'community event' => ['communityEvent', Feature::CommunityEvent],
            'community event comment' => ['communityEventComment', Feature::CommunityEvent],
        ];
    }

    #[DataProvider('ownerUnits')]
    public function test_a_switched_off_unit_denies_its_own_files(string $alias, Feature $feature): void
    {
        $viewer = Member::factory()->create();
        $file = $this->fileOwnedBy($alias, $viewer);

        $this->assertTrue(Gate::forUser($viewer)->allows('view', $file));

        $this->switchOff($feature);

        $this->assertFalse(Gate::forUser($viewer)->allows('view', $file));
    }

    /**
     * The schema permits a feature-owned file to also carry the public override; the unit's state
     * is decided first, so the mark cannot keep a switched-off unit's bytes guest-readable.
     */
    public function test_the_public_visibility_override_does_not_outrank_a_switched_off_unit(): void
    {
        $viewer = Member::factory()->create();
        $file = $this->fileOwnedBy('diary', $viewer);
        $file->forceFill(['explicit_visibility' => File::VISIBILITY_PUBLIC])->save();

        // On: the public mark opens it, to a guest too.
        $this->assertTrue(Gate::forUser(null)->allows('view', $file));

        $this->switchOff(Feature::Diary);

        $this->assertFalse(Gate::forUser(null)->allows('view', $file));
        $this->assertFalse(Gate::forUser($viewer)->allows('view', $file));
    }

    public function test_switching_communities_off_denies_a_topic_attachment(): void
    {
        // The board's own flag stays on: Feature::enabled() walks the parent chain, so a file only
        // ever names the unit it belongs to.
        $viewer = Member::factory()->create();
        $file = $this->fileOwnedBy('communityTopic', $viewer);

        $this->switchOff(Feature::Group);

        $this->assertDatabaseMissing('sns_settings', ['key' => Feature::GroupTopic->settingKey()->value]);
        $this->assertFalse(Gate::forUser($viewer)->allows('view', $file));
    }

    public function test_avatars_and_banners_survive_every_unit_going_off(): void
    {
        $viewer = Member::factory()->create();
        $avatar = $this->fileOwnedBy('member', $viewer);
        $banner = $this->fileOwnedBy('bannerImage', $viewer);
        $publicAsset = File::factory()->create([
            'related_entity_type' => null,
            'related_entity_id' => null,
            'explicit_visibility' => File::VISIBILITY_PUBLIC,
        ]);

        foreach (Feature::cases() as $feature) {
            $this->setSnsSetting($feature->settingKey(), false);
        }
        $this->freshRequestState();

        $this->assertTrue(Gate::forUser($viewer)->allows('view', $avatar));
        $this->assertTrue(Gate::forUser(null)->allows('view', $banner));
        $this->assertTrue(Gate::forUser(null)->allows('view', $publicAsset));
    }

    public function test_the_admin_file_monitor_still_reads_a_switched_off_units_bytes(): void
    {
        // Operators keep moderating a unit they switched off. The admin route carries its own guard
        // and never consults FilePolicy, which is what makes that survive.
        $diary = Diary::factory()->create(['member_id' => Member::factory()->create()->getKey()]);
        $file = $this->fileWithBytes('diary-bytes', [
            'type' => 'image/png',
            'related_entity_type' => 'diary',
            'related_entity_id' => $diary->getKey(),
        ]);

        $this->switchOff(Feature::Diary);
        $this->actingAs(AdminUser::factory()->create(), 'admin');

        $response = $this->get(route('admin.file.raw', ['file' => $file->name]))->assertOk();
        $this->assertSame('diary-bytes', $response->streamedContent());
    }

    private function switchOff(Feature $feature): void
    {
        $this->setSnsSetting($feature->settingKey(), false);
        $this->freshRequestState();
    }

    private function fileOwnedBy(string $alias, Member $viewer): File
    {
        $group = fn (): Group => Group::factory()->create();

        /** @var Model $owner */
        $owner = match ($alias) {
            'member' => $viewer,
            'bannerImage' => BannerImage::factory()->create(),
            'diary' => Diary::factory()->create(['member_id' => $viewer->getKey()]),
            'diaryComment' => DiaryComment::factory()->create([
                'diary_id' => Diary::factory()->create(['member_id' => $viewer->getKey()])->getKey(),
                'member_id' => $viewer->getKey(),
            ]),
            'directMessage' => DirectMessage::factory()->create(['sender_id' => $viewer->getKey()]),
            'timelinePost' => TimelinePost::factory()->create(['member_id' => $viewer->getKey()]),
            'community', 'group' => $group(),
            'communityTopic' => GroupTopic::factory()->create(['group_id' => $group()->getKey()]),
            'communityTopicComment' => GroupTopicComment::factory()->create(),
            'communityEvent' => CommunityEvent::factory()->create(['community_id' => $group()->getKey()]),
            'communityEventComment' => CommunityEventComment::factory()->create(),
        };

        return File::factory()->create([
            'type' => 'image/png',
            'related_entity_type' => $alias,
            'related_entity_id' => $owner->getKey(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function fileWithBytes(string $content, array $attributes): File
    {
        $file = File::factory()->create($attributes + ['byte_size' => strlen($content)]);

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $content);
        rewind($stream);
        $this->app->make(FileStorage::class)->writeStream($file, $stream);
        fclose($stream);

        return $file;
    }
}
