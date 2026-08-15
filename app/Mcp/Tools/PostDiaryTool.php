<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\Diary\Actions\CreateDiary;
use App\Features\Diary\Data\DiaryFormData;
use App\Features\Diary\DiaryVisibility;
use App\Features\Diary\Serializers\McpDiarySerializer;
use App\Mcp\McpAbilities;
use App\Rules\MaxBytes;
use App\Support\BodyFormat;
use App\Support\Visibility;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('post-diary')]
#[Title('Post a diary')]
#[Description('Write a diary entry as yourself, visible immediately. Mind the audience: an entry for all members — the default unless your account prefers another — or for anyone on the web notifies every member of the site, by mail and on the site. Post at private when it is a note for yourself.')]
class PostDiaryTool extends DiaryTool
{
    public function handle(Request $request, CreateDiary $create): Response|ResponseFactory
    {
        $member = $this->member($request);

        // The endpoint only ever asked for mcp:read, so writing is checked again here — that is what
        // makes a read-only token a read-only token.
        if (! $member->tokenCan(McpAbilities::WRITE)) {
            return Response::error(self::MISSING_WRITE);
        }

        // The audiences the compose form would offer, keyed by the slug this wire speaks: accepting
        // one is the same act as resolving it, so a tier the site does not offer (Open with
        // web-public off, Friends with the friend unit off) cannot arrive by another spelling.
        $offered = self::offeredBySlug();

        /** @var array{title: string, body: string, visibility?: string, format?: string} $validated */
        $validated = Validator::make(
            [
                // Trimmed here, not left to middleware: the direct tool path never meets TrimStrings,
                // so what the compose form stores and what this stores would otherwise differ — an
                // entry titled with three spaces is refused there and written here.
                'title' => self::trimmed($request, 'title'),
                'body' => self::trimmed($request, 'body'),
                // Only when they were actually sent: `sometimes` treats a key holding null as an
                // argument to check, and null is neither an audience nor a format.
                ...$request->only(['visibility', 'format']),
            ],
            [
                // The title's column is TEXT too, and the web form leaves it to the column; here the
                // cap is stated, as the comment body's is, so an oversize one is a refusal and not a
                // database error.
                'title' => ['required', 'string', new MaxBytes(self::BODY_MAX_BYTES)],
                'body' => ['required', 'string', new MaxBytes(self::BODY_MAX_BYTES)],
                // A refused audience names the field: it is the caller's own choice, and says nothing
                // about what exists.
                'visibility' => ['sometimes', 'string', Rule::in(array_keys($offered))],
                // op3 is never author-able: it exists only on bodies migrated from OpenPNE 3.
                'format' => ['sometimes', 'string', Rule::in([BodyFormat::Plain->value, BodyFormat::Markdown->value])],
            ],
        )->validate();

        // Omitted means the member's own default, clamped to what is offered — the audience the
        // compose form pre-selects for them, not a constant.
        $visibility = isset($validated['visibility'])
            ? $offered[$validated['visibility']]
            : DiaryVisibility::defaultFor($member);

        $diary = $create($member, new DiaryFormData(
            title: $validated['title'],
            body: $validated['body'],
            visibility: $visibility,
            format: isset($validated['format']) ? BodyFormat::from($validated['format']) : null,
        ));

        $diary->setRelation('member', $member);

        return Response::structured(['diary' => McpDiarySerializer::summary($diary)]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required()->description('The entry\'s title.'),
            'body' => $schema->string()->required()
                ->description('The entry\'s text, at most '.self::BODY_MAX_BYTES.' bytes — bytes, not characters, so a Japanese one costs about three each.'),
            'visibility' => $schema->string()->enum(array_keys(self::offeredBySlug()))
                ->description('Who may read it. Omitted, your account\'s own default is used (all members unless you have set another). open: anyone on the web. members: everyone signed in. friends: your friends. private: only you. Which of these are offered is the site\'s decision, and an audience it does not offer is refused.'),
            'format' => $schema->string()->enum([BodyFormat::Plain->value, BodyFormat::Markdown->value])->default(BodyFormat::Plain->value)
                ->description('How the text is read: plain leaves it as typed, markdown renders it.'),
        ];
    }

    /**
     * The offered audiences by slug. Slugs, not the stored ints: Open is 0, and a raw 0 on a wire
     * reads as "no audience".
     *
     * @return array<string, Visibility>
     */
    private static function offeredBySlug(): array
    {
        $offered = [];

        foreach (DiaryVisibility::options() as $tier) {
            $offered[$tier->slug()] = $tier;
        }

        return $offered;
    }
}
