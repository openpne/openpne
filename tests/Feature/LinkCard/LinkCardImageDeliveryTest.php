<?php

declare(strict_types=1);

namespace Tests\Feature\LinkCard;

use App\Features\GroupTopic\TopicReadAccess;
use App\Files\FileStorage;
use App\LinkCard\CardContext;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\LinkCard;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\LinkCardStatus;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * One picture can sit under a public post and a private one at once, so these tests are about the
 * post-decides-not-the-file separation holding under substitution.
 */
class LinkCardImageDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Member $author;

    private LinkCard $card;

    private File $image;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, true);
        $this->author = Member::factory()->create();
        $this->card = LinkCard::factory()->create();
        $this->image = $this->imageFor($this->card);
    }

    public function test_a_viewer_who_can_read_the_post_gets_the_picture(): void
    {
        $diary = $this->diary(Visibility::Open);

        $this->actingAs($this->author)
            ->get($this->urlFor($diary))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_a_guest_gets_a_web_public_post_card(): void
    {
        // The bytes a web-public diary shows have to render for the signed-out reader it is for.
        $diary = $this->diary(Visibility::Open);

        $this->get($this->urlFor($diary))->assertOk();
    }

    public function test_the_response_is_not_cacheable_by_anything_shared(): void
    {
        $diary = $this->diary(Visibility::Open);

        $this->actingAs($this->author)
            ->get($this->urlFor($diary))
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_same_card_is_public_through_one_post_and_private_through_another(): void
    {
        // One card, one File, two posts: the answer must come from the post in the URL, never from
        // the most permissive post that shares the picture.
        $open = $this->diary(Visibility::Open);
        $private = $this->diary(Visibility::Private);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)->get($this->urlFor($open))->assertOk();
        $this->actingAs($stranger)->get($this->urlFor($private))->assertNotFound();
    }

    public function test_a_stranger_cannot_swap_in_a_post_they_can_read(): void
    {
        // The image name alone must not be a capability: pairing it with any readable post would
        // otherwise unlock every card image on the site.
        $private = $this->diary(Visibility::Private);
        $stranger = Member::factory()->create();
        $theirOwn = Diary::factory()->for($stranger)->create(['visibility' => Visibility::Open, 'link_card_id' => $this->card->id]);

        // Their own post genuinely carries this card, so this one is allowed…
        $this->actingAs($stranger)->get($this->urlFor($theirOwn))->assertOk();
        // …while the private post it also sits under is not.
        $this->actingAs($stranger)->get($this->urlFor($private))->assertNotFound();
    }

    public function test_a_post_that_does_not_carry_this_card_refuses(): void
    {
        // The record id in the URL is not evidence: the post must actually point at the card.
        $unrelated = Diary::factory()->for($this->author)->create(['visibility' => Visibility::Open]);

        $this->actingAs($this->author)
            ->get(route('linkCard.image', $this->urlParts($unrelated)))
            ->assertNotFound();
    }

    public function test_an_image_belonging_to_another_card_refuses(): void
    {
        // Neither is the file name: pointing this endpoint at some other stored image by pairing it
        // with a readable post must not work.
        $diary = $this->diary(Visibility::Open);
        $otherCard = LinkCard::factory()->create();
        $otherImage = $this->imageFor($otherCard);

        $this->actingAs($this->author)
            ->get(route('linkCard.image', array_merge($this->urlParts($diary), ['name' => $otherImage->name])))
            ->assertNotFound();
    }

    public function test_an_unrelated_file_refuses(): void
    {
        // An avatar or a diary photo has no link_card relation, so it can never be served here.
        $diary = $this->diary(Visibility::Open);
        $avatar = File::factory()->create(['type' => 'image/png', 'related_entity_type' => 'member']);

        $this->actingAs($this->author)
            ->get(route('linkCard.image', array_merge($this->urlParts($diary), ['name' => $avatar->name])))
            ->assertNotFound();
    }

    public function test_a_url_that_outlived_the_post_being_edited_refuses(): void
    {
        $diary = $this->diary(Visibility::Open);
        $url = $this->urlFor($diary);

        $diary->forceFill(['link_card_id' => null, 'link_card_synced_at' => null])->saveQuietly();

        $this->actingAs($this->author)->get($url)->assertNotFound();
    }

    public function test_a_url_that_outlived_the_image_being_replaced_refuses(): void
    {
        $diary = $this->diary(Visibility::Open);
        $url = $this->urlFor($diary);

        $this->card->update(['image_file_id' => $this->imageFor($this->card)->id]);

        $this->actingAs($this->author)->get($url)->assertNotFound();
    }

    public function test_a_url_that_outlived_the_post_being_made_private_refuses(): void
    {
        // The page may already be in someone's browser; the picture must stop resolving anyway.
        $diary = $this->diary(Visibility::Open);
        $stranger = Member::factory()->create();
        $url = $this->urlFor($diary);
        $this->actingAs($stranger)->get($url)->assertOk();

        $diary->update(['visibility' => Visibility::Private]);

        $this->actingAs($stranger)->get($url)->assertNotFound();
    }

    public function test_a_url_that_outlived_the_post_being_deleted_refuses(): void
    {
        $diary = $this->diary(Visibility::Open);
        $url = $this->urlFor($diary);

        $diary->delete();

        $this->actingAs($this->author)->get($url)->assertNotFound();
    }

    public function test_turning_the_feature_off_stops_serving(): void
    {
        $diary = $this->diary(Visibility::Open);
        $url = $this->urlFor($diary);

        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, false);

        $this->actingAs($this->author)->get($url)->assertNotFound();
    }

    public function test_a_card_that_is_not_renderable_is_not_served(): void
    {
        $diary = $this->diary(Visibility::Open);
        $url = $this->urlFor($diary);

        $this->card->update(['status' => LinkCardStatus::Failed]);

        $this->actingAs($this->author)->get($url)->assertNotFound();
    }

    public function test_a_context_slug_outside_the_list_does_not_resolve(): void
    {
        // The URL may choose which post is consulted, never which model the app resolves.
        $diary = $this->diary(Visibility::Open);

        foreach (['member', 'App%5CModels%5CMember', 'message', 'diaries'] as $slug) {
            $this->actingAs($this->author)
                ->get(str_replace('/linkCard/diary/', "/linkCard/{$slug}/", $this->urlFor($diary)))
                ->assertNotFound();
        }
    }

    public function test_a_community_body_follows_its_board_rule(): void
    {
        // Whatever the board admits the card admits, including the part that is not a restriction:
        // an Everyone board is readable by any signed-in member, so its cards are too.
        $closed = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $open = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        GroupMember::factory()->create(['group_id' => $closed->id, 'member_id' => $this->author->id]);
        $stranger = Member::factory()->create();

        foreach ([$closed, $open] as $group) {
            $topic = GroupTopic::factory()->for($group)->for($this->author, 'member')
                ->create(['link_card_id' => $this->card->id]);
            // Talk reads the same column the board does, so the same three answers have to come back
            // for something said in the room.
            $message = GroupMessage::factory()->for($group)->for($this->author, 'author')
                ->create(['link_card_id' => $this->card->id]);

            foreach ([$topic, $message] as $record) {
                $this->actingAs($this->author)->get($this->urlFor($record))->assertOk();

                // The explicit logout is load-bearing: `actingAs` holds for the rest of the test, so
                // without it this would re-ask as the member above and pass while proving nothing.
                $this->app['auth']->forgetGuards();
                $this->get($this->urlFor($record))->assertNotFound();

                $this->actingAs($stranger)->get($this->urlFor($record))
                    ->assertStatus($group->topic_read_access === TopicReadAccess::MembersOnly ? 404 : 200);
            }
        }
    }

    public function test_a_message_from_another_room_is_answered_by_that_room(): void
    {
        // The talk lookup is deliberately not scoped to a group, so an id from a conversation the
        // asker may not read resolves and is then refused by its room's rule, not by the URL's shape.
        $mine = Group::factory()->create();
        $theirs = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        GroupMember::factory()->create(['group_id' => $mine->id, 'member_id' => $this->author->id]);
        $elsewhere = GroupMessage::factory()->for($theirs)->for(Member::factory()->create(), 'author')
            ->create(['link_card_id' => $this->card->id]);

        $this->actingAs($this->author)->get($this->urlFor($elsewhere))->assertNotFound();
    }

    public function test_every_body_kind_is_served_through_its_own_rule(): void
    {
        $group = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->id, 'member_id' => $this->author->id]);

        $records = [
            'diary' => $this->diary(Visibility::Open),
            'topic' => GroupTopic::factory()->for($group)->for($this->author, 'member')->create(['link_card_id' => $this->card->id]),
            'event' => GroupEvent::factory()->for($group)->for($this->author, 'member')->create(['link_card_id' => $this->card->id]),
            'timeline' => TimelinePost::factory()->for($this->author)->create(['visibility' => Visibility::Open, 'link_card_id' => $this->card->id]),
            'talk' => GroupMessage::factory()->for($group)->for($this->author, 'author')->create(['link_card_id' => $this->card->id]),
            'diaryComment' => DiaryComment::factory()->for($this->diary(Visibility::Open))->for($this->author, 'member')->create(['link_card_id' => $this->card->id]),
            'topicComment' => GroupTopicComment::factory()
                ->for(GroupTopic::factory()->for($group)->for($this->author, 'member')->create(), 'topic')
                ->for($this->author, 'member')->create(['link_card_id' => $this->card->id]),
            'eventComment' => GroupEventComment::factory()
                ->for(GroupEvent::factory()->for($group)->for($this->author, 'member')->create(), 'event')
                ->for($this->author, 'member')->create(['link_card_id' => $this->card->id]),
        ];

        // "Every" is the claim, so it is checked rather than assumed: a kind added to the enum and
        // forgotten here would otherwise leave this passing while proving less than its name says.
        $this->assertSame(
            array_map(fn (CardContext $context): string => $context->value, CardContext::cases()),
            array_keys($records),
        );

        foreach ($records as $kind => $record) {
            $this->assertStringContainsString("/linkCard/{$kind}/", (string) $this->urlFor($record), "{$kind}: wrong context slug.");
            $this->actingAs($this->author)->get($this->urlFor($record))->assertOk();
        }
    }

    public function test_a_record_with_no_card_has_no_url(): void
    {
        $bare = Diary::factory()->for($this->author)->create(['visibility' => Visibility::Open]);

        $this->assertNull(CardContext::imageUrl($bare, 120, 120, true));
    }

    public function test_a_card_with_no_image_has_no_url(): void
    {
        $imageless = LinkCard::factory()->create(['image_file_id' => null]);
        $diary = Diary::factory()->for($this->author)->create(['visibility' => Visibility::Open, 'link_card_id' => $imageless->id]);

        $this->assertNull(CardContext::imageUrl($diary->fresh(), 120, 120, true));
    }

    public function test_a_size_outside_the_whitelist_refuses(): void
    {
        $diary = $this->diary(Visibility::Open);

        $this->actingAs($this->author)
            ->get(route('linkCard.image', array_merge($this->urlParts($diary), ['geometry' => 'w9999_h9999'])))
            ->assertNotFound();
    }

    public function test_a_card_pointing_at_a_file_that_is_not_a_card_image_refuses(): void
    {
        // Everything else here trusts `link_cards.image_file_id`; this is the one check that does
        // not, so a card whose image has come to name an avatar serves nothing rather than the avatar.
        $diary = $this->diary(Visibility::Open);
        $avatar = File::factory()->create(['type' => 'image/png', 'related_entity_type' => 'member']);
        $this->storePng($avatar);
        $this->card->update(['image_file_id' => $avatar->id]);

        $this->actingAs($this->author)
            ->get(route('linkCard.image', array_merge($this->urlParts($diary), ['name' => $avatar->name])))
            ->assertNotFound();
    }

    public function test_a_format_that_does_not_match_the_stored_image_refuses(): void
    {
        // The path says what to serve and the file says what it is; a disagreement is not a request
        // to transcode, it means the URL was built for something that is no longer there.
        $diary = $this->diary(Visibility::Open);

        $this->actingAs($this->author)
            ->get(route('linkCard.image', array_merge($this->urlParts($diary), ['format' => 'jpg', 'ext' => 'jpg'])))
            ->assertNotFound();

        // The OpenPNE 3-shaped URL names the format twice, and the two halves must agree.
        $this->actingAs($this->author)
            ->get(route('linkCard.image', array_merge($this->urlParts($diary), ['ext' => 'jpg'])))
            ->assertNotFound();
    }

    public function test_switching_the_owning_module_off_stops_serving(): void
    {
        // The bytes are fetched by URL, so no page mediates them: turning a module off has to stop
        // its card images too, or they stay readable while every screen around them is gone.
        $group = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->id, 'member_id' => $this->author->id]);

        $urls = [
            [SnsSettingKey::FeatureDiaryEnabled, $this->urlFor($this->diary(Visibility::Open))],
            [SnsSettingKey::FeatureTimelineEnabled, $this->urlFor(
                TimelinePost::factory()->for($this->author)->create(['visibility' => Visibility::Open, 'link_card_id' => $this->card->id])
            )],
            [SnsSettingKey::FeatureGroupTopicEnabled, $this->urlFor(
                GroupTopic::factory()->for($group)->for($this->author, 'member')->create(['link_card_id' => $this->card->id])
            )],
            [SnsSettingKey::FeatureGroupEventEnabled, $this->urlFor(
                GroupEvent::factory()->for($group)->for($this->author, 'member')->create(['link_card_id' => $this->card->id])
            )],
            [SnsSettingKey::FeatureGroupTalkEnabled, $this->urlFor(
                GroupMessage::factory()->for($group)->for($this->author, 'author')->create(['link_card_id' => $this->card->id])
            )],
        ];

        foreach ($urls as [$key, $url]) {
            $this->actingAs($this->author)->get($url)->assertOk();
            $this->setSnsSetting($key, false);
            $this->actingAs($this->author)->get($url)->assertNotFound();
            $this->setSnsSetting($key, true);
        }
    }

    public function test_switching_communities_off_stops_serving_their_bodies_cards(): void
    {
        // The parent unit, not just the board's own flag: a topic is unreachable while groups
        // are off whatever `communityTopic` says.
        $group = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->id, 'member_id' => $this->author->id]);
        $topic = GroupTopic::factory()->for($group)->for($this->author, 'member')->create(['link_card_id' => $this->card->id]);
        $event = GroupEvent::factory()->for($group)->for($this->author, 'member')->create(['link_card_id' => $this->card->id]);
        $message = GroupMessage::factory()->for($group)->for($this->author, 'author')->create(['link_card_id' => $this->card->id]);

        $this->setSnsSetting(SnsSettingKey::FeatureGroupEnabled, false);

        $this->actingAs($this->author)->get($this->urlFor($topic))->assertNotFound();
        $this->actingAs($this->author)->get($this->urlFor($event))->assertNotFound();
        $this->actingAs($this->author)->get($this->urlFor($message))->assertNotFound();
    }

    public function test_a_reply_is_authorised_by_its_thread_root_not_by_its_own_author(): void
    {
        // The replier's friend is the audience the reply's own rule would admit, and they cannot
        // open the thread the card sits in.
        $root = TimelinePost::factory()->for($this->author)->create(['visibility' => Visibility::Friends]);
        $replier = Member::factory()->create();
        $this->makeFriends($this->author, $replier);
        $reply = TimelinePost::factory()->for($replier)->create([
            'in_reply_to_id' => $root->id,
            'visibility' => Visibility::Friends,
            'link_card_id' => $this->card->id,
        ]);

        $friendOfTheReplierOnly = Member::factory()->create();
        $this->makeFriends($replier, $friendOfTheReplierOnly);

        // They cannot open the thread…
        $this->actingAs($friendOfTheReplierOnly)->get("/timeline/{$root->id}")->assertNotFound();
        // …so they cannot have the picture out of it either.
        $this->actingAs($friendOfTheReplierOnly)->get($this->urlFor($reply))->assertNotFound();

        // The audience the page was gated for does get it.
        $this->actingAs($replier)->get($this->urlFor($reply))->assertOk();
    }

    public function test_a_reply_whose_thread_is_gone_is_refused_rather_than_judged_on_its_own(): void
    {
        // Asserted against the predicate rather than through a request, because the database will
        // not hold the state (the foreign key cascades), so the guard is for a reply arriving without
        // its root by some other route.
        $root = TimelinePost::factory()->for($this->author)->create(['visibility' => Visibility::Open]);
        $reply = TimelinePost::factory()->for($this->author)->create([
            'in_reply_to_id' => $root->id,
            'visibility' => Visibility::Open,
            'link_card_id' => $this->card->id,
        ]);
        $reply->setRelation('parent', null);

        $this->assertTrue(CardContext::TimelinePost->canView($reply->fresh(), $this->author), 'The thread is readable while its root is there.');
        $this->assertFalse(CardContext::TimelinePost->canView($reply, $this->author), 'A reply with no thread was judged on its own rule.');
    }

    public function test_a_reply_addresses_its_picture_like_any_other_row(): void
    {
        // The audience is the test above; this one is the address.
        $root = TimelinePost::factory()->for($this->author)->create(['visibility' => Visibility::Open]);
        $reply = TimelinePost::factory()->for(Member::factory()->create())->create([
            'visibility' => Visibility::Open,
            'in_reply_to_id' => $root->id,
            'link_card_id' => $this->card->id,
        ]);

        $url = $this->urlFor($reply);

        $this->assertStringContainsString("/linkCard/timeline/{$reply->id}/", (string) $url);
        $this->actingAs($this->author)->get($url)->assertOk();
    }

    private function diary(Visibility $visibility): Diary
    {
        return Diary::factory()->for($this->author)->create([
            'visibility' => $visibility,
            'link_card_id' => $this->card->id,
        ]);
    }

    private function imageFor(LinkCard $card): File
    {
        $file = File::factory()->create([
            'type' => 'image/png',
            'related_entity_type' => 'link_card',
            'related_entity_id' => $card->id,
        ]);
        $card->update(['image_file_id' => $file->id]);
        $this->storePng($file);

        return $file;
    }

    private function storePng(File $file): void
    {
        $image = imagecreatetruecolor(40, 40);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);
        $this->app->make(FileStorage::class)->writeStream($file, $stream);
    }

    private function urlFor(object $record): ?string
    {
        return CardContext::imageUrl($record->fresh(), 120, 120, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function urlParts(object $record): array
    {
        $kind = CardContext::forRecord($record);

        return [
            'context' => $kind?->value,
            'record' => $record->getKey(),
            'format' => 'png',
            'geometry' => 'w120_h120_sq',
            'name' => $this->image->name,
            'ext' => 'png',
        ];
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }
}
