<?php

declare(strict_types=1);

namespace Tests\Feature\LinkCard;

use App\Features\GroupTopic\TopicReadAccess;
use App\Files\FileStorage;
use App\LinkCard\CardContext;
use App\Models\Diary;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\LinkCard;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\LinkCardStatus;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A card image is shared: the same picture can sit under a world-readable post and a private one at
 * the same moment. Nothing about the File decides who may see it — only the post being looked at
 * does — so these tests are almost all about that separation holding under substitution.
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
        // The answer depends on who asked. A cached copy that outlives a post going private is the
        // failure this endpoint exists to prevent, so there is no window in which one may exist.
        $diary = $this->diary(Visibility::Open);

        $this->actingAs($this->author)
            ->get($this->urlFor($diary))
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_same_card_is_public_through_one_post_and_private_through_another(): void
    {
        // The case the whole design exists for. One card, one File, two posts — and the answer must
        // come from the post in the URL, never from the most permissive post that happens to share
        // the picture.
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
        // Whatever the board admits, the card admits — including the part that is not a restriction:
        // an Everyone board is readable by any signed-in member, so its cards are too. What must not
        // happen is the endpoint inventing a rule of its own in either direction.
        $closed = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $open = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        GroupMember::factory()->create(['group_id' => $closed->id, 'member_id' => $this->author->id]);
        $stranger = Member::factory()->create();

        foreach ([$closed, $open] as $group) {
            $topic = GroupTopic::factory()->for($group)->for($this->author, 'member')
                ->create(['link_card_id' => $this->card->id]);

            $this->actingAs($this->author)->get($this->urlFor($topic))->assertOk();

            // Signed out has no case to express on a community board, whichever it is. The explicit
            // logout is load-bearing: actingAs holds for the rest of the test, so without it this
            // would re-ask as the member above and pass while proving nothing.
            $this->app['auth']->forgetGuards();
            $this->get($this->urlFor($topic))->assertNotFound();

            $this->actingAs($stranger)->get($this->urlFor($topic))
                ->assertStatus($group->topic_read_access === TopicReadAccess::MembersOnly ? 404 : 200);
        }
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
        ];

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
        // Defence in depth against the row being wrong rather than the URL. Everything else here
        // trusts `link_cards.image_file_id`; this is the one check that does not, so that a card
        // whose image has come to name an avatar serves nothing rather than serving the avatar.
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

        $this->setSnsSetting(SnsSettingKey::FeatureGroupEnabled, false);

        $this->actingAs($this->author)->get($this->urlFor($topic))->assertNotFound();
        $this->actingAs($this->author)->get($this->urlFor($event))->assertNotFound();
    }

    public function test_a_timeline_reply_is_never_addressable(): void
    {
        // A permalink to a reply re-centers to its thread root and is authorised as the root, so a
        // card URL naming the reply would ask a different audience than the page it appears on.
        // Replies are never synced, so this is defence against that changing, not a live hole.
        $root = TimelinePost::factory()->for($this->author)->create(['visibility' => Visibility::Open]);
        $reply = TimelinePost::factory()->for(Member::factory()->create())->create([
            'visibility' => Visibility::Open,
            'in_reply_to_id' => $root->id,
            'link_card_id' => $this->card->id,
        ]);

        $this->assertNull($this->urlFor($reply));

        // …and not merely absent from the page: the address cannot be constructed by hand either.
        $this->actingAs($this->author)
            ->get(route('linkCard.image', array_merge($this->urlParts($reply), ['record' => $reply->id])))
            ->assertNotFound();
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
}
