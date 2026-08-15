<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Features\GroupTopic\TopicReadAccess;
use App\Files\FileStorage;
use App\Files\FileUploader;
use App\Files\ImageCache;
use App\Files\ImageTransform;
use App\Mcp\McpAbilities;
use App\Mcp\Servers\OpenPneServer;
use App\Mcp\Tools\ReadTalkMessageImagesTool;
use App\Models\File;
use App\Models\GroupMessage;
use App\Models\GroupMessageImage;
use App\Models\Member;
use App\Support\Feature;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * read-talk-message-images: the bytes behind the count the other tools report, under the same
 * refusal, and under a cap that answers before a picture is in memory.
 */
class TalkImageToolTest extends McpTestCase
{
    /** The tool's own per-response cap, as a caller experiences it. */
    private const CAP = 8 * 1024 * 1024;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('image_cache');
    }

    /** A read-only token is all this tool ever needs. */
    private function acting(Member $member): Member
    {
        return Sanctum::actingAs($member, [McpAbilities::READ]);
    }

    private function attach(GroupMessage $message, int $number, int $width = 800, int $height = 400): File
    {
        return $this->link(
            $message,
            $number,
            app(FileUploader::class)->store(
                UploadedFile::fake()->image("shot{$number}.png", $width, $height),
                'groupMessage',
                (int) $message->getKey(),
            ),
        );
    }

    private function link(GroupMessage $message, int $number, File $file): File
    {
        GroupMessageImage::factory()->create([
            'group_message_id' => $message->getKey(),
            'file_id' => $file->getKey(),
            'number' => $number,
        ]);

        return $file;
    }

    /** The bytes as stored, which is what the original size answers with. */
    private function stored(File $file): string
    {
        $stream = app(FileStorage::class)->readStream($file);
        $bytes = (string) stream_get_contents($stream);
        fclose($stream);

        return $bytes;
    }

    /** The 640px variant off the same cache the tool reads. */
    private function thumbnail(File $file): string
    {
        return app(ImageCache::class)->bytes($file, ImageTransform::fromGeometry('w640_h640'), 'png');
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function read(array $arguments): TestResponse
    {
        return OpenPneServer::tool(ReadTalkMessageImagesTool::class, $arguments);
    }

    /** Image content travels base64-encoded, so this is the exact bytes appearing on the wire. */
    private function wire(string $bytes): string
    {
        return base64_encode($bytes);
    }

    public function test_a_picture_comes_back_as_thumbnail_bytes_with_the_shape_it_was_returned_at(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $message = $this->say($group, $member, 'look at this');
        $file = $this->attach($message, 1, 800, 400);
        $thumbnail = $this->thumbnail($file);

        $this->acting($member);

        $this->read(['group_id' => $group->getKey(), 'message_id' => $message->getKey()])
            ->assertOk()
            ->assertSee($this->wire($thumbnail))
            // Fitted into the 640 box, so the reported size is the thumbnail's and not the source's.
            ->assertStructuredContent(['images' => [[
                'number' => 1,
                'width' => 640,
                'height' => 320,
                'mimeType' => 'image/png',
                'byteSize' => strlen($thumbnail),
            ]]]);
    }

    public function test_the_original_size_answers_with_the_stored_bytes_untouched(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $message = $this->say($group, $member, 'full size please');
        $file = $this->attach($message, 1, 800, 400);
        $stored = $this->stored($file);

        $this->acting($member);

        $this->read(['group_id' => $group->getKey(), 'message_id' => $message->getKey(), 'size' => 'original'])
            ->assertOk()
            ->assertSee($this->wire($stored))
            ->assertStructuredContent(['images' => [[
                'number' => 1,
                'width' => 800,
                'height' => 400,
                'mimeType' => 'image/png',
                'byteSize' => strlen($stored),
            ]]]);

        $this->assertSame($file->byte_size, strlen($stored));
    }

    public function test_naming_a_slot_answers_with_that_picture_alone(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $message = $this->say($group, $member, 'two of them');
        $first = $this->attach($message, 1, 800, 400);
        $second = $this->attach($message, 2, 400, 800);

        $this->acting($member);

        $this->read([
            'group_id' => $group->getKey(),
            'message_id' => $message->getKey(),
            'size' => 'original',
            'number' => 2,
        ])
            ->assertOk()
            ->assertSee($this->wire($this->stored($second)))
            ->assertDontSee($this->wire($this->stored($first)))
            ->assertStructuredContent(fn ($json) => $json
                ->count('images', 1)
                ->where('images.0.number', 2)
                ->where('images.0.width', 400)
                ->etc());
    }

    public function test_every_picture_comes_back_in_slot_order(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $message = $this->say($group, $member, 'a set');
        $files = [
            $this->attach($message, 1, 800, 400),
            $this->attach($message, 2, 400, 800),
            $this->attach($message, 3, 200, 100),
        ];

        $this->acting($member);

        $response = $this->read(['group_id' => $group->getKey(), 'message_id' => $message->getKey()])->assertOk();

        foreach ($files as $file) {
            $response->assertSee($this->wire($this->thumbnail($file)));
        }

        // Slot order, and the smallest one is not upscaled into the box.
        $response->assertStructuredContent(['images' => [
            ['number' => 1, 'width' => 640, 'height' => 320, 'mimeType' => 'image/png', 'byteSize' => strlen($this->thumbnail($files[0]))],
            ['number' => 2, 'width' => 320, 'height' => 640, 'mimeType' => 'image/png', 'byteSize' => strlen($this->thumbnail($files[1]))],
            ['number' => 3, 'width' => 200, 'height' => 100, 'mimeType' => 'image/png', 'byteSize' => strlen($this->thumbnail($files[2]))],
        ]]);
    }

    public function test_a_message_without_pictures_answers_with_none_rather_than_a_refusal(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $message = $this->say($group, $member, 'just words');

        $this->acting($member);

        $this->read(['group_id' => $group->getKey(), 'message_id' => $message->getKey()])
            ->assertOk()
            ->assertStructuredContent(['images' => []]);
    }

    public function test_a_room_or_message_the_caller_may_not_read_never_yields_its_pictures(): void
    {
        $private = $this->group(TopicReadAccess::MembersOnly);
        $hidden = $this->say($private, $this->memberOf($private), 'members only');
        $secret = $this->attach($hidden, 1);

        $open = $this->group();
        $outsider = $this->memberOf($open);
        $ownMessage = $this->say($open, $outsider, 'mine');

        // A second room this caller may read, so the mismatch is the only thing wrong with the pair.
        $elsewhere = $this->group();
        $wrongRoom = $this->say($elsewhere, $this->memberOf($elsewhere), 'over here');
        $strayed = $this->attach($wrongRoom, 1, 200, 100);

        $this->acting($outsider);

        $refusals = [
            // A room this caller may not read.
            ['group_id' => $private->getKey(), 'message_id' => $hidden->getKey()],
            // A room that does not exist at all.
            ['group_id' => $private->getKey() + 9999, 'message_id' => $hidden->getKey()],
            // A readable room, but the message belongs to another one — readable or not.
            ['group_id' => $open->getKey(), 'message_id' => $hidden->getKey()],
            ['group_id' => $open->getKey(), 'message_id' => $wrongRoom->getKey()],
            // A message id that names nothing.
            ['group_id' => $open->getKey(), 'message_id' => $ownMessage->getKey() + 9999],
        ];

        foreach ($refusals as $arguments) {
            $this->read($arguments)
                ->assertHasErrors(['No such talk room'])
                ->assertDontSee([$this->wire($this->stored($secret)), $this->wire($this->stored($strayed))]);
        }
    }

    public function test_switching_talk_off_takes_the_picture_tool_away(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $message = $this->say($group, $member, 'look at this');
        $this->attach($message, 1);

        $this->acting($member);
        $this->setSnsSetting(Feature::GroupTalk->settingKey(), false);

        $this->read(['group_id' => $group->getKey(), 'message_id' => $message->getKey()])
            ->assertHasErrors(['not found']);
    }

    public function test_a_slot_that_holds_no_picture_is_refused_when_named_and_passed_over_when_not(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $message = $this->say($group, $member, 'mixed');
        $this->attach($message, 1, 800, 400);
        $this->link($message, 2, File::factory()->create([
            'type' => 'application/pdf',
            'related_entity_type' => 'groupMessage',
            'related_entity_id' => $message->getKey(),
        ]));
        $this->attach($message, 3, 200, 100);

        $this->acting($member);

        $this->read(['group_id' => $group->getKey(), 'message_id' => $message->getKey()])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->count('images', 2)
                ->where('images.0.number', 1)
                ->where('images.1.number', 3)
                ->etc());

        foreach ([2, 9] as $number) {
            $this->read([
                'group_id' => $group->getKey(),
                'message_id' => $message->getKey(),
                'number' => $number,
            ])->assertHasErrors(['No such talk room']);
        }
    }

    public function test_more_bytes_than_a_call_may_return_is_refused_before_any_are_read(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $message = $this->say($group, $member, 'two big ones');
        $first = $this->attach($message, 1, 800, 400);
        $second = $this->attach($message, 2, 400, 800);

        // Recorded sizes only: the stored bytes stay small, so the refusal can only come from the
        // preflight — nothing here is big enough to trip the check on what was actually read.
        $first->update(['byte_size' => intdiv(self::CAP, 2) + 1]);
        $second->update(['byte_size' => intdiv(self::CAP, 2) + 1]);

        $this->acting($member);

        $this->read(['group_id' => $group->getKey(), 'message_id' => $message->getKey(), 'size' => 'original'])
            ->assertHasErrors(['8 MB'])
            ->assertDontSee($this->wire($this->stored($first)));

        // One at a time fits, which is what the refusal tells the caller to do.
        $this->read([
            'group_id' => $group->getKey(),
            'message_id' => $message->getKey(),
            'size' => 'original',
            'number' => 1,
        ])
            ->assertOk()
            ->assertSee($this->wire($this->stored($first)));
    }

    public function test_bytes_that_outgrow_their_recorded_size_are_refused_whole(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $message = $this->say($group, $member, 'one honest, one not');
        $honest = $this->attach($message, 1, 800, 400);

        // A row that understates what it stores: the preflight lets it through, and only the running
        // total of what has actually been read can stop it.
        $liar = $this->link($message, 2, File::factory()->create([
            'type' => 'image/png',
            'related_entity_type' => 'groupMessage',
            'related_entity_id' => $message->getKey(),
            'byte_size' => 1024,
        ]));
        $this->write($liar, str_repeat('a', self::CAP + 1));

        $this->acting($member);

        $this->read(['group_id' => $group->getKey(), 'message_id' => $message->getKey(), 'size' => 'original'])
            ->assertHasErrors(['8 MB'])
            // Nothing partial: the picture that was read before the total ran over does not go back.
            ->assertDontSee($this->wire($this->stored($honest)));
    }

    private function write(File $file, string $bytes): void
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);
        app(FileStorage::class)->writeStream($file, $stream);
        fclose($stream);
    }
}
