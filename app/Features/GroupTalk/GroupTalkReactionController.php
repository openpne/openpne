<?php

namespace App\Features\GroupTalk;

use App\Features\GroupTalk\Actions\AddMessageReaction;
use App\Features\GroupTalk\Actions\RemoveMessageReaction;
use App\Features\GroupTalk\Serializers\GroupMessageSerializer;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Http\Controllers\Controller;
use App\Http\Requests\GroupTalk\StoreReactionRequest;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Reaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The emoji on a talk message, kept apart from GroupTalkController — that one already carries the
 * conversation itself.
 *
 * Adding and removing are two URLs rather than one toggle: a tap that is retried, arrives twice, or
 * races the poll has to settle at the state the member asked for, not at whichever parity the round
 * trips landed on. Both are gated on posting — reacting is speaking in the room — while the reactor
 * list is gated on reading. Every refusal is the talk's usual 404.
 */
class GroupTalkReactionController extends Controller
{
    public function store(StoreReactionRequest $request, Group $group, GroupMessage $message, AddMessageReaction $action): JsonResponse
    {
        $this->authorizeWrite($group, $message);

        $action($this->viewer(), $message, $request->validated('emoji'));

        return $this->state($group, $message);
    }

    /**
     * Taking a reaction back. The emoji is checked for shape only, never against
     * App\Features\Reactions\ReactionVocabulary: a member holds reactions from whatever the site
     * offered at the time, and narrowing the vocabulary must not strand them.
     */
    public function delete(Request $request, Group $group, GroupMessage $message, RemoveMessageReaction $action): JsonResponse
    {
        $this->authorizeWrite($group, $message);
        $emoji = (string) $request->validate(['emoji' => ['required', 'string', 'max:32']])['emoji'];

        $action($this->viewer(), $message, $emoji);

        return $this->state($group, $message);
    }

    /**
     * Who reacted, grouped by emoji — what the chip row's dialog fetches, and only then: the names
     * are the one part of a reaction whose size grows with the room.
     */
    public function index(Group $group, GroupMessage $message): JsonResponse
    {
        abort_unless($message->group_id === $group->getKey(), 404);
        abort_unless(GroupTalkAccess::canView($group, $this->viewer()), 404);

        $groups = $message->reactions()
            ->with('member.avatar.file')
            ->get()
            ->groupBy('emoji')
            ->map(fn (Collection $rows, string $emoji): array => [
                'emoji' => $emoji,
                // A reactor always exists: the row cascades with the member.
                'members' => $rows->map(fn (Reaction $reaction): array => MemberRefSerializer::ref($reaction->member))->values()->all(),
            ])
            ->values()
            ->all();

        return response()->json(['groups' => $groups]);
    }

    /**
     * The gate both writes share. The path names the group as well as the message, so a pair that
     * does not match is a malformed URL rather than something to act on — the same reading
     * GroupTalkController::delete takes.
     */
    private function authorizeWrite(Group $group, GroupMessage $message): void
    {
        abort_unless($message->group_id === $group->getKey(), 404);
        abort_unless(GroupTalkAccess::canPost($group, $this->viewer()), 404);
    }

    /**
     * The message's whole chip row after the write. The client patches the row with this rather than
     * applying its own delta, so a reaction someone else added meanwhile lands in the same answer.
     */
    private function state(Group $group, GroupMessage $message): JsonResponse
    {
        $message->load('reactions');

        return response()->json([
            'reactions' => GroupMessageSerializer::reactions($message, GroupTalkPermissions::for($group, $this->viewer())),
        ]);
    }
}
