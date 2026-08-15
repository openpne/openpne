<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Files\ImageBytesOverLimitException;
use App\Files\ImageCache;
use App\Files\ImageDimensions;
use App\Files\ImageTransform;
use App\Models\File;
use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('read-talk-message-images')]
#[Title('Read the pictures on a talk message')]
#[Description('The pictures attached to one message of a group talk room, as image data. read-talk-messages says which messages have any, in imageCount.')]
#[IsReadOnly]
class ReadTalkMessageImagesTool extends TalkTool
{
    private const SIZES = ['thumbnail', 'original'];

    /** A rung of the existing ladder (config openpne.images.allowed_sizes), fitted rather than cropped. */
    private const THUMBNAIL = 'w640_h640';

    /** The stored bytes as they are: ImageTransform::isRaw() sends ImageCache straight past the decoder. */
    private const ORIGINAL = 'w_h';

    /**
     * The most bytes one call may answer with. A picture costs a client far more context than a
     * message does, and a room of photographs would otherwise fill it in a single call.
     */
    private const MAX_BYTES = 8 * 1024 * 1024;

    private const NO_THUMBNAIL = 'This site does not offer the thumbnail size these tools ask for. Ask for size=original.';

    public function handle(Request $request, ImageCache $cache): Response|ResponseFactory
    {
        $validated = $request->validate([
            'group_id' => ['required', 'integer', 'min:1'],
            'message_id' => ['required', 'integer', 'min:1'],
            'size' => ['sometimes', 'string', 'in:'.implode(',', self::SIZES)],
            'number' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $member = $this->member($request);
        $group = $this->readableRoom($member, (int) $validated['group_id']);
        if ($group === null) {
            return $this->refused();
        }

        // Scoped to the room the caller just passed the gate on: a message of another room is no more
        // distinguishable here than an id that names nothing.
        $message = GroupMessage::query()
            ->where('group_id', $group->getKey())
            ->whereKey((int) $validated['message_id'])
            ->first();
        if ($message === null) {
            return $this->refused();
        }

        // Never a geometry built by hand: fromGeometry() is where the size whitelist is applied, and
        // a caller-driven size is exactly what it exists to keep out of the cache.
        $transform = ImageTransform::fromGeometry(
            ($validated['size'] ?? 'thumbnail') === 'original' ? self::ORIGINAL : self::THUMBNAIL,
        );
        if ($transform === null) {
            // Only reachable where an operator has taken the thumbnail rung out of the whitelist.
            return Response::error(self::NO_THUMBNAIL);
        }

        $targets = $this->targets($member, $message, isset($validated['number']) ? (int) $validated['number'] : null);

        return $targets instanceof Response ? $targets : $this->answer($cache, $transform, $targets);
    }

    /**
     * The slots to answer with, in number order, or the refusal that stands for all of them.
     *
     * A named slot that holds no picture — never attached, not an image, its file gone — is refused
     * rather than answered empty, so the numbers cannot be walked to count what a message carries.
     * With no number those same slots are simply passed over: what was asked for is the message's
     * pictures, and a slot that is not one is not missing from that.
     *
     * @return Response|list<array{0: int, 1: File}>
     */
    private function targets(Member $member, GroupMessage $message, ?int $number): Response|array
    {
        $slots = $message->images()
            ->with('file')
            ->when($number !== null, fn ($query) => $query->where('number', $number))
            ->get();

        $targets = [];

        foreach ($slots as $slot) {
            $file = $slot->file;

            // Belt. FilePolicy sends a talk image to GroupTalkAccess::canView — the gate this room was
            // already resolved through — so it cannot deny one row of a message the caller may read.
            // Asked anyway, and a denial takes the whole answer rather than dropping a picture from it.
            if ($file !== null && ! Gate::forUser($member)->allows('view', $file)) {
                return $this->refused();
            }

            if ($file === null || $file->imageFormat() === null) {
                if ($number !== null) {
                    return $this->refused();
                }

                continue;
            }

            $targets[] = [(int) $slot->number, $file];
        }

        return $number !== null && $targets === [] ? $this->refused() : $targets;
    }

    /**
     * @param  list<array{0: int, 1: File}>  $targets
     */
    private function answer(ImageCache $cache, ImageTransform $transform, array $targets): Response|ResponseFactory
    {
        // The declared sizes are the only measure available before any bytes are in memory, and
        // answering before they are is the whole point of a cap. They are the originals' sizes even
        // when thumbnails were asked for: conservative rather than exact, a thumbnail being smaller
        // than its source and never larger.
        $declared = array_sum(array_map(fn (array $target): int => (int) $target[1]->byte_size, $targets));

        if ($declared > self::MAX_BYTES) {
            return $this->tooLarge();
        }

        $images = [];
        $described = [];
        $read = 0;

        foreach ($targets as [$number, $file]) {
            // byte_size is metadata, and metadata can disagree with the bytes it describes. What is
            // left of the cap goes down with the request so a row that understates its file stops the
            // read there, rather than the file arriving whole and being measured afterwards.
            try {
                $bytes = $cache->bytes(
                    $file,
                    $transform,
                    (string) $file->imageFormat(),
                    maxBytes: self::MAX_BYTES - $read,
                );
            } catch (ImageBytesOverLimitException) {
                return $this->tooLarge();
            }

            $read += strlen($bytes);

            // Belt on the bound above, and where a cached thumbnail — served without one — is counted.
            // Nothing partial goes back either way: an answer trimmed to fit is one the caller cannot
            // tell from a whole one.
            if ($read > self::MAX_BYTES) {
                return $this->tooLarge();
            }

            // Measured off what is being returned rather than read off the file row, whose size is the
            // source's. Null when these bytes do not decode here at all.
            [$width, $height] = ImageDimensions::fromBytes($bytes) ?? [null, null];

            $images[] = Response::image($bytes, (string) $file->type);
            $described[] = [
                'number' => $number,
                'width' => $width,
                'height' => $height,
                'mimeType' => (string) $file->type,
                'byteSize' => strlen($bytes),
            ];
        }

        return Response::make($images)->withStructuredContent(['images' => $described]);
    }

    private function tooLarge(): Response
    {
        return Response::error(
            'These pictures come to more than the '.intdiv(self::MAX_BYTES, 1024 * 1024)
            .' MB one call may return. Ask for a single one with number, or for size=thumbnail.',
        );
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'group_id' => $schema->integer()->min(1)->required()
                ->description('The room the message is in, as list-talk-rooms reports it in groupId.'),
            'message_id' => $schema->integer()->min(1)->required()
                ->description('The message to look at, as read-talk-messages reports it in id.'),
            'size' => $schema->string()->enum(self::SIZES)->default('thumbnail')
                ->description('thumbnail: fitted into a 640px box, which is enough to see what a picture is and a fraction of the context the original costs. original: the bytes as they were uploaded — ask for it only when the detail decides something.'),
            'number' => $schema->integer()->min(1)
                ->description('One picture of the message, numbered from 1 in the order they were attached. Omit it for every picture on the message, which is also the way to learn how they are numbered.'),
        ];
    }
}
