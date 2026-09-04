<?php

namespace Tests\Feature\File;

use App\Files\FileStorage;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DirectMessage;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Gate is unit-tested in FilePolicyTest; this walks the same matrix over HTTP, because only a
 * request proves reachability.
 */
class GuestFileDeliveryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, bool}> owner morph alias => guest may read */
    public static function ownerTypes(): array
    {
        return [
            // Shown on web-public pages, and OpenPNE 3 put no login in front of image delivery.
            'member avatar' => ['member', true],
            'web-public diary' => ['diary', true],
            'web-public diary comment' => ['diaryComment', true],
            // Members-only surfaces: a guest never had a page to reach them from.
            'members-only diary' => ['diary:members', false],
            'group (legacy alias)' => ['community', false],
            'group (write alias)' => ['group', false],
            'community topic' => ['communityTopic', false],
            'community topic comment' => ['communityTopicComment', false],
            'community event' => ['communityEvent', false],
            'community event comment' => ['communityEventComment', false],
            'direct message attachment' => ['directMessage', false],
            // Fail-closed by construction.
            'unlinked' => ['', false],
            'unknown type' => ['widget', false],
        ];
    }

    #[DataProvider('ownerTypes')]
    public function test_guest_delivery_matches_the_owning_entity(string $owner, bool $readable): void
    {
        $file = $this->fileOwnedBy($owner);

        $response = $this->get($file->url());

        // Denial is 404, never a login redirect: the response tells a guest nothing about the file.
        $readable ? $response->assertOk() : $response->assertNotFound();
        $this->get($file->thumbnailUrl(120, 120, square: true))->assertStatus($readable ? 200 : 404);
    }

    public function test_a_web_public_timeline_image_follows_the_sns_setting(): void
    {
        // Opening file delivery to guests activates the FilePolicy timeline branch, so the branch
        // has to honour the admin switch — otherwise turning web-public posting off still serves
        // the bytes of every Open post.
        $post = TimelinePost::factory()->create([
            'member_id' => Member::factory()->create()->getKey(),
            'visibility' => Visibility::Open,
        ]);
        $file = $this->file('timelinePost', (int) $post->getKey());

        $this->get($file->url())->assertNotFound();

        $this->setSnsSetting(SnsSettingKey::TimelineAllowWebPublic, true);
        $this->get($file->url())->assertOk();
    }

    private function fileOwnedBy(string $owner): File
    {
        [$alias, $variant] = array_pad(explode(':', $owner, 2), 2, null);

        $author = Member::factory()->create();
        $webPublicDiary = fn (): Diary => Diary::factory()->create([
            'member_id' => $author->getKey(),
            'visibility' => Visibility::Open,
        ]);

        $ownerModel = match ($alias) {
            '' => null,
            'widget' => null,
            'member' => $author,
            'diary' => $variant === 'members'
                ? Diary::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members])
                : $webPublicDiary(),
            'diaryComment' => DiaryComment::factory()->create([
                'diary_id' => $webPublicDiary()->getKey(),
                'member_id' => $author->getKey(),
            ]),
            'community', 'group' => Group::factory()->create(),
            'communityTopic' => GroupTopic::factory()->create(),
            'communityTopicComment' => GroupTopicComment::factory()->create(),
            'communityEvent' => GroupEvent::factory()->create(),
            'communityEventComment' => GroupEventComment::factory()->create(),
            'directMessage' => DirectMessage::factory()->create(),
        };

        return $this->file(
            $alias === '' ? null : $alias,
            $ownerModel instanceof Model ? (int) $ownerModel->getKey() : ($alias === 'widget' ? 1 : null),
        );
    }

    private function file(?string $type, ?int $id): File
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        $file = File::factory()->create([
            'type' => 'image/png',
            'related_entity_type' => $type,
            'related_entity_id' => $id,
            'byte_size' => strlen($bytes),
        ]);

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);
        app(FileStorage::class)->writeStream($file, $stream);
        fclose($stream);

        return $file;
    }
}
