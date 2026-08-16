<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Files\ImageCache;
use App\Mcp\Tools\Concerns\AnswersWithImages;
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
    use AnswersWithImages;

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

        $transform = $this->transformFor($validated['size'] ?? null);
        if ($transform === null) {
            return Response::error(self::NO_THUMBNAIL);
        }

        $targets = $this->targets($member, $message, isset($validated['number']) ? (int) $validated['number'] : null);

        return $targets instanceof Response ? $targets : $this->answerWithImages($cache, $transform, $targets);
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
