<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\Diary\Actions\CreateDiary;
use App\Features\Diary\Data\DiaryFormData;
use App\Features\Diary\DiaryVisibility;
use App\Features\Diary\Serializers\McpDiarySerializer;
use App\Mcp\McpAbilities;
use App\Mcp\Tools\Concerns\DecodesImageUploads;
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
#[Description('Write a diary entry as yourself, visible immediately. Mind the audience: an entry for all members — the default unless your account prefers another — or for anyone on the web is announced to the site\'s active members (minus those who opted out of the mail), by mail and on the site. Post at private when it is a note for yourself.')]
class PostDiaryTool extends DiaryTool
{
    use DecodesImageUploads;

    public function handle(Request $request, CreateDiary $create): Response|ResponseFactory
    {
        $member = $this->member($request);

        if (! $member->tokenCan(McpAbilities::WRITE)) {
            return Response::error(self::MISSING_WRITE);
        }

        // Accepting a slug is the same act as resolving it, so a tier the site does not offer cannot
        // arrive by another spelling.
        $offered = self::offeredBySlug();

        /** @var array{title: string, body: string, visibility?: string, format?: string} $validated */
        $validated = Validator::make(
            [
                'title' => self::trimmed($request, 'title'),
                'body' => self::trimmed($request, 'body'),
                // Only when they were actually sent: `sometimes` treats a key holding null as an
                // argument to check, and null is neither an audience nor a format.
                ...$request->only(['visibility', 'format']),
            ],
            [
                // The title's column is TEXT too and the web form leaves it to the column; capped
                // here so an oversize title is a refusal rather than a database error.
                'title' => ['required', 'string', new MaxBytes(self::BODY_MAX_BYTES)],
                'body' => ['required', 'string', new MaxBytes(self::BODY_MAX_BYTES)],
                // A refused audience names the field: it is the caller's own choice, and says nothing
                // about what exists.
                'visibility' => ['sometimes', 'string', Rule::in(array_keys($offered))],
                // op3 is never author-able: it exists only on bodies migrated from OpenPNE 3.
                'format' => ['sometimes', 'string', Rule::in([BodyFormat::Plain->value, BodyFormat::Markdown->value])],
            ],
        )->validate();

        $visibility = isset($validated['visibility'])
            ? $offered[$validated['visibility']]
            : DiaryVisibility::defaultFor($member);

        $data = new DiaryFormData(
            title: $validated['title'],
            body: $validated['body'],
            visibility: $visibility,
            format: isset($validated['format']) ? BodyFormat::from($validated['format']) : null,
        );

        return $this->withImageUploads($request, function (array $images) use ($create, $member, $data): Response|ResponseFactory {
            $diary = $create($member, $data, $images);

            $diary->setRelation('member', $member);

            return Response::structured(['diary' => McpDiarySerializer::summary($diary)]);
        });
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
            'images' => $this->imagesSchema($schema),
        ];
    }

    /**
     * Slugs, not the stored ints: Open is 0, and a raw 0 on a wire reads as "no audience".
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
