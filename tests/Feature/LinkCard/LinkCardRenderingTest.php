<?php

declare(strict_types=1);

namespace Tests\Feature\LinkCard;

use App\Files\FileStorage;
use App\LinkCard\LinkCardSerializer;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\GroupTopic;
use App\Models\LinkCard;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\BodyFormat;
use App\Support\LinkCardStatus;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The card is drawn from a page nobody here controls, so these tests are mostly about what does
 * *not* happen: no markup crosses into the body, no field escapes escaping, and no card outlives
 * the switch that produced it.
 */
class LinkCardRenderingTest extends TestCase
{
    use RefreshDatabase;

    private Member $author;

    private LinkCard $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, true);
        $this->author = Member::factory()->create();
        $this->card = LinkCard::factory()->create([
            'url' => 'https://www.example.com/article?id=7',
            'title' => 'A title from the page',
            'description' => 'What the page says it is about.',
            'site_name' => 'Example',
            'status' => LinkCardStatus::Ok,
            // Fetched and fresh. Left due, the read trigger would queue a real fetch — the queue is
            // sync under test — and these are about drawing a card, not acquiring one.
            'fetched_at' => now(),
            'expires_at' => now()->addDays(7),
            'next_attempt_at' => null,
        ]);
        $this->card->update(['image_file_id' => $this->storedImage()->id]);
    }

    public function test_the_modern_diary_detail_carries_the_card(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $diary = $this->diary();

        $this->actingAs($this->author)->get("/diary/{$diary->id}")
            ->assertInertia(fn ($page) => $page
                ->where('diary.linkCard.title', 'A title from the page')
                ->where('diary.linkCard.description', 'What the page says it is about.')
                ->where('diary.linkCard.url', 'https://www.example.com/article?id=7')
                // The host, without www — the reader is told where the link goes, not what the page
                // calls itself.
                ->where('diary.linkCard.domain', 'example.com')
                ->etc());
    }

    public function test_the_card_image_is_addressed_through_the_post_not_the_file(): void
    {
        // What makes the picture safe to serve is the post in its URL; a bare file URL would be
        // authorised against nothing in particular.
        config(['openpne.surface_mode' => 'modern_default']);
        $diary = $this->diary();

        $this->actingAs($this->author)->get("/diary/{$diary->id}")
            ->assertInertia(fn ($page) => $page
                ->where('diary.linkCard.imageUrl', fn (?string $url) => $url !== null
                    && str_contains($url, "/linkCard/diary/{$diary->id}/img/"))
                ->etc());
    }

    public function test_a_big_landscape_picture_asks_for_the_full_width_shape(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $this->card->image->update(['width' => 1200, 'height' => 630]);
        $diary = $this->diary();

        $this->actingAs($this->author)->get("/diary/{$diary->id}")
            ->assertInertia(fn ($page) => $page
                ->where('diary.linkCard.layout', 'wide')
                // The size the bytes render at, for the box the client reserves before they arrive.
                ->where('diary.linkCard.imageWidth', 1200)
                ->where('diary.linkCard.imageHeight', 630)
                ->where('diary.linkCard.fitSources', function (Collection $sources): bool {
                    $rows = $sources->map(fn ($source) => (array) $source)->all();

                    // Never a square crop: the whole point of this shape is the picture's own ratio,
                    // and `_sq` would flatten it back to a tile.
                    return array_column($rows, 'box') === [320, 640, 1200]
                        && ! str_contains(implode(' ', array_column($rows, 'url')), '_sq');
                })
                ->etc());
    }

    public function test_a_small_picture_stays_the_thumbnail_shape(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $this->card->image->update(['width' => 100, 'height' => 100]);
        $diary = $this->diary();

        $this->actingAs($this->author)->get("/diary/{$diary->id}")
            ->assertInertia(fn ($page) => $page
                ->where('diary.linkCard.layout', 'compact')
                ->where('diary.linkCard.fitSources', [])
                ->etc());
    }

    public function test_the_thumbnail_url_is_the_same_whichever_shape_was_chosen(): void
    {
        // The square thumbnail is what every surface draws today. Whichever shape the server names,
        // the address it hands over stays the one the current renderers ask for — so nothing on
        // screen moves until the renderers are taught the second shape.
        config(['openpne.surface_mode' => 'modern_default']);
        $diary = $this->diary();

        $compact = $this->actingAs($this->author)->get("/diary/{$diary->id}")
            ->viewData('page')['props']['diary']['linkCard'];

        $this->card->image->update(['width' => 1200, 'height' => 630]);

        $wide = $this->actingAs($this->author)->get("/diary/{$diary->id}")
            ->viewData('page')['props']['diary']['linkCard'];

        $this->assertSame('compact', $compact['layout']);
        $this->assertSame('wide', $wide['layout']);
        $this->assertSame($compact['imageUrl'], $wide['imageUrl']);
        $this->assertStringContainsString('_sq', (string) $wide['imageUrl']);
    }

    public function test_the_classic_diary_detail_renders_the_card(): void
    {
        $diary = $this->diary();

        $this->actingAs($this->author)->get("/diary/{$diary->id}")
            ->assertOk()
            ->assertSee('A title from the page')
            ->assertSee('What the page says it is about.')
            ->assertSee('example.com')
            ->assertSee('rel="noopener noreferrer nofollow"', false);
    }

    public function test_the_classic_card_reads_host_first_and_puts_a_wide_picture_last(): void
    {
        $this->card->image->update(['width' => 1200, 'height' => 630]);
        $diary = $this->diary();

        $html = $this->actingAs($this->author)->get("/diary/{$diary->id}")->assertOk()->getContent();

        // Matched as markup, never as a bare class name: the card's stylesheet names every one of
        // these classes, and a substring probe over the page finds the rules before the card.
        $host = strpos($html, '<span class="linkCardDomain">');
        $title = strpos($html, '<span class="linkCardTitle">');
        $banner = strpos($html, '<img class="linkCardBanner"');

        $this->assertIsInt($banner, 'The wide shape drew no picture.');
        $this->assertLessThan($title, $host, 'The host must be read before the claim, not after it.');
        $this->assertLessThan($banner, $title, 'The picture belongs under the words.');
        // The square thumbnail is the other shape's; drawing both would be two pictures.
        $this->assertStringNotContainsString('<span class="linkCardImage">', $html);
    }

    public function test_the_classic_wide_picture_is_held_to_the_same_box_as_modern_and_never_enlarged(): void
    {
        // The smallest picture the shape admits is 267x200, and a Classic card is ~460 wide, so
        // `width: 100%` on its own stretches it by 1.7. The cap is the formula Modern writes
        // (link-card.test.tsx; Modern's carries the ratio in parentheses): the box a member's own
        // picture gets, then the source's width — one surface shipped without the other's cap once.
        // LinkCardLayoutParityTest holds the two boxes to the same rems.
        $this->card->image->update(['width' => 267, 'height' => 200]);
        $diary = $this->diary();

        $html = $this->actingAs($this->author)->get("/diary/{$diary->id}")->assertOk()->getContent();

        $this->assertStringContainsString('<img class="linkCardBanner"', $html);
        $this->assertStringContainsString('style="max-width: min(100%, 24rem, 267px, calc(20rem * 1.91))"', $html);
    }

    public function test_the_classic_card_keeps_the_thumbnail_beside_the_words_when_it_is_small(): void
    {
        $this->card->image->update(['width' => 100, 'height' => 100]);
        $diary = $this->diary();

        $html = $this->actingAs($this->author)->get("/diary/{$diary->id}")->assertOk()->getContent();

        $this->assertStringContainsString('<span class="linkCardImage">', $html);
        $this->assertStringNotContainsString('<img class="linkCardBanner"', $html);
        $this->assertLessThan(strpos($html, '<span class="linkCardTitle">'), strpos($html, '<span class="linkCardDomain">'));
    }

    public function test_every_body_kind_renders_its_card(): void
    {
        $group = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->id, 'member_id' => $this->author->id]);
        $topic = GroupTopic::factory()->for($group)->for($this->author, 'member')->create(['link_card_id' => $this->card->id, 'link_card_synced_at' => now()]);
        $event = GroupEvent::factory()->for($group)->for($this->author, 'member')->create(['link_card_id' => $this->card->id, 'link_card_synced_at' => now()]);
        $post = TimelinePost::factory()->for($this->author)->create(['visibility' => Visibility::Open, 'link_card_id' => $this->card->id, 'link_card_synced_at' => now()]);
        GroupMessage::factory()->for($group)->for($this->author, 'author')->create(['link_card_id' => $this->card->id, 'link_card_synced_at' => now()]);

        // Every surface draws a card in either shape without falling over. Which shape it drew is
        // not visible here — the Modern four decide that client-side — so that is asserted where it
        // can be seen: the Classic pair below, and link-card.test.tsx.
        foreach ([[100, 100], [1200, 630]] as [$width, $height]) {
            $this->card->image->update(['width' => $width, 'height' => $height]);

            foreach ([
                "/topics/{$topic->id}",
                "/events/{$event->id}",
                "/timeline/{$post->id}",
                "/groups/{$group->id}/talk",
            ] as $url) {
                $this->actingAs($this->author)->get($url)->assertOk()->assertSee('A title from the page');
            }
        }
    }

    public function test_a_comment_carries_its_card_on_both_surfaces(): void
    {
        $diary = Diary::factory()->for($this->author)->create(['visibility' => Visibility::Open]);
        DiaryComment::factory()->for($diary)->for($this->author, 'member')->create([
            'body' => 'See https://www.example.com/article?id=7',
            'link_card_id' => $this->card->id,
            'link_card_synced_at' => now(),
        ]);

        // Classic draws it in the comment's own block, after the words.
        $html = $this->actingAs($this->author)->get("/diary/{$diary->id}")->assertOk()->getContent();
        $this->assertStringContainsString('<span class="linkCardImage">', $html);

        config(['openpne.surface_mode' => 'modern_default']);
        $this->actingAs($this->author)->get("/diary/{$diary->id}")
            ->assertInertia(fn ($page) => $page->where('comments.0.linkCard.title', 'A title from the page')->etc());
    }

    public function test_card_text_is_escaped_not_rendered(): void
    {
        // A card is assembled from a page we do not control. The body pipeline's guarantee is that
        // trusted HTML comes only from BodyRenderer, and a card is not body: every field is text.
        $this->card->update([
            'title' => '<script>alert(1)</script>',
            'description' => '<img src=x onerror=alert(2)>',
        ]);
        $diary = $this->diary();

        $response = $this->actingAs($this->author)->get("/diary/{$diary->id}");

        // Asserted as markup, not as a substring: the escaped form still *contains* the words
        // `onerror=alert(2)`, harmlessly, so only the presence of a real tag distinguishes the two.
        $response->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x', false)
            ->assertSee('<script>alert(1)</script>')
            ->assertSee('<img src=x onerror=alert(2)>');
    }

    public function test_the_card_is_not_part_of_the_body_html(): void
    {
        // Rendering it as structured data next to the body, rather than inside it, is what keeps the
        // sanitizer allowlist (no img, no iframe) and the single-trusted-HTML-source rule intact.
        config(['openpne.surface_mode' => 'modern_default']);
        $diary = $this->diary(['format' => BodyFormat::Markdown, 'body' => 'See https://www.example.com/article?id=7']);

        $this->actingAs($this->author)->get("/diary/{$diary->id}")
            ->assertInertia(fn ($page) => $page
                ->where('diary.bodyHtml', fn (?string $html) => $html !== null
                    && ! str_contains($html, 'A title from the page')
                    && ! str_contains($html, '<img'))
                ->etc());
    }

    public function test_switching_link_cards_off_stops_rendering_them(): void
    {
        // Including cards already fetched: the switch is about what this site shows, not only about
        // what it goes on to fetch.
        $diary = $this->diary();
        $this->actingAs($this->author)->get("/diary/{$diary->id}")->assertSee('A title from the page');

        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, false);

        $this->actingAs($this->author)->get("/diary/{$diary->id}")->assertDontSee('A title from the page');
    }

    public function test_a_card_with_nothing_to_show_is_not_drawn(): void
    {
        // A failed fetch and a page that carried no metadata are the same thing to a reader: the
        // bare link is better than an empty box.
        foreach ([['status' => LinkCardStatus::Failed], ['status' => LinkCardStatus::Ok, 'title' => null]] as $attributes) {
            $this->card->update($attributes);
            $diary = $this->diary();

            $this->actingAs($this->author)->get("/diary/{$diary->id}")
                ->assertOk()
                ->assertDontSee('linkCard');
        }
    }

    public function test_a_card_without_a_picture_still_draws(): void
    {
        $this->card->update(['image_file_id' => null]);
        $diary = $this->diary();

        $this->actingAs($this->author)->get("/diary/{$diary->id}")
            ->assertOk()
            ->assertSee('A title from the page')
            // The class name itself is in the stylesheet either way; what must be absent is the
            // element.
            ->assertDontSee('<span class="linkCardImage">', false);
    }

    public function test_a_reply_in_a_thread_carries_its_card(): void
    {
        // The thread serializer shapes replies and roots alike, so this is the same shape the root
        // gets — which is why the picture and the words could never have been enabled apart.
        config(['openpne.surface_mode' => 'modern_default']);
        $root = TimelinePost::factory()->for($this->author)->create(['visibility' => Visibility::Open]);
        $reply = TimelinePost::factory()->for($this->author)->create([
            'visibility' => Visibility::Open,
            'in_reply_to_id' => $root->id,
            'link_card_id' => $this->card->id,
            'link_card_synced_at' => now(),
        ]);

        $this->assertNotNull(LinkCardSerializer::card($reply->fresh(), $this->author));

        $this->actingAs($this->author)->get("/timeline/{$root->id}")
            ->assertInertia(fn ($page) => $page->where('replies.0.linkCard.title', 'A title from the page')->etc());
    }

    public function test_the_classic_thread_draws_a_reply_card(): void
    {
        // Classic's thread and its feed row are different templates: the feed's card comes from
        // `_post.blade.php`, and a reply is drawn by `show.blade.php` itself.
        $root = TimelinePost::factory()->for($this->author)->create(['visibility' => Visibility::Open]);
        TimelinePost::factory()->for($this->author)->create([
            'visibility' => Visibility::Open,
            'in_reply_to_id' => $root->id,
            'link_card_id' => $this->card->id,
            'link_card_synced_at' => now(),
        ]);

        $this->actingAs($this->author)->get("/timeline/{$root->id}")
            ->assertOk()
            ->assertSee('A title from the page')
            ->assertSee('<span class="linkCardImage">', false);
    }

    public function test_a_thread_costs_the_same_whatever_the_replies_carry(): void
    {
        // The same guard the feed has, on the page this change put cards on. A reply's card costs
        // three queries of its own without the eager load: the read trigger's freshness check, the
        // serializer's card, and that card's picture.
        config(['openpne.surface_mode' => 'modern_default']);
        $root = TimelinePost::factory()->for($this->author)->create(['visibility' => Visibility::Open, 'link_card_synced_at' => now()]);
        // With pictures, deliberately: `$card->image` costs no query when `image_file_id` is null, so
        // a guard built on the factory's default watches two of the three reads it names — and stays
        // green when the picture is dropped from the eager load.
        foreach (range(1, 5) as $ignored) {
            $card = LinkCard::factory()->create(['status' => LinkCardStatus::Ok, 'title' => 'A title from the page']);
            $card->update(['image_file_id' => $this->imageFor($card)->id]);
            TimelinePost::factory()->for($this->author)->create([
                'visibility' => Visibility::Open,
                'in_reply_to_id' => $root->id,
                'link_card_id' => $card->id,
                'link_card_synced_at' => now(),
            ]);
        }

        $this->actingAs($this->author)->get("/timeline/{$root->id}");
        DB::enableQueryLog();
        $this->actingAs($this->author)->get("/timeline/{$root->id}")->assertOk()->assertSee('A title from the page');
        $withCards = count(DB::getQueryLog());
        DB::flushQueryLog();

        TimelinePost::query()->whereNotNull('in_reply_to_id')->update(['link_card_id' => null]);
        $this->actingAs($this->author)->get("/timeline/{$root->id}")->assertOk();
        $withoutCards = count(DB::getQueryLog());
        DB::disableQueryLog();

        // One query for the replies' cards and one for their images, however many replies carry them.
        $this->assertLessThanOrEqual($withoutCards + 2, $withCards, "A thread cost {$withCards} queries with cards against {$withoutCards} without.");
    }

    public function test_a_timeline_list_costs_the_same_whatever_the_cards(): void
    {
        // The Classic timeline row is shared by the feed, the profile and three gadgets, so a card
        // read per row would multiply across every one of them.
        // With pictures — see the thread guard for why a card without one leaves this watching two
        // reads of three.
        foreach (range(1, 5) as $ignored) {
            $card = LinkCard::factory()->create(['status' => LinkCardStatus::Ok, 'title' => 'A title from the page']);
            $card->update(['image_file_id' => $this->imageFor($card)->id]);
            TimelinePost::factory()->for($this->author)->create(['visibility' => Visibility::Open, 'link_card_id' => $card->id, 'link_card_synced_at' => now()]);
        }

        $this->actingAs($this->author)->get('/timeline');
        DB::enableQueryLog();
        $this->actingAs($this->author)->get('/timeline')->assertOk()->assertSee('A title from the page');
        $withCards = count(DB::getQueryLog());
        DB::flushQueryLog();

        TimelinePost::query()->update(['link_card_id' => null]);
        $this->actingAs($this->author)->get('/timeline')->assertOk();
        $withoutCards = count(DB::getQueryLog());
        DB::disableQueryLog();

        // One extra query for the cards and one for their images, however many rows carry them.
        $this->assertLessThanOrEqual($withoutCards + 2, $withCards);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function diary(array $attributes = []): Diary
    {
        return Diary::factory()->for($this->author)->create($attributes + [
            'visibility' => Visibility::Open,
            'link_card_id' => $this->card->id,
            // Marked examined, as a real post is by the time it renders. Without it the read trigger
            // runs SyncLinkCard inline, reads a factory body with no URL in it, and detaches the very
            // card under test — leaving assertions that pass because nothing was drawn at all.
            'link_card_synced_at' => now(),
        ]);
    }

    private function storedImage(): File
    {
        $file = File::factory()->create([
            'type' => 'image/png',
            'related_entity_type' => 'link_card',
            'related_entity_id' => $this->card->id,
        ]);

        $image = imagecreatetruecolor(40, 40);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);
        $this->app->make(FileStorage::class)->writeStream($file, $stream);

        return $file;
    }

    /** A stored picture belonging to $card, as the fetch job would have left one. */
    private function imageFor(LinkCard $card): File
    {
        return File::factory()->create([
            'type' => 'image/png',
            'related_entity_type' => 'link_card',
            'related_entity_id' => $card->id,
        ]);
    }
}
