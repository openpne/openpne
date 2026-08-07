<?php

declare(strict_types=1);

namespace Tests\Feature\LinkCard;

use App\Files\FileStorage;
use App\LinkCard\LinkCardSerializer;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityMember;
use App\Models\CommunityTopic;
use App\Models\Diary;
use App\Models\File;
use App\Models\LinkCard;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\BodyFormat;
use App\Support\LinkCardStatus;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_every_body_kind_renders_its_card(): void
    {
        $community = Community::factory()->create();
        CommunityMember::factory()->create(['community_id' => $community->id, 'member_id' => $this->author->id]);
        $topic = CommunityTopic::factory()->for($community)->for($this->author, 'member')->create(['link_card_id' => $this->card->id, 'link_card_synced_at' => now()]);
        $event = CommunityEvent::factory()->for($community)->for($this->author, 'member')->create(['link_card_id' => $this->card->id, 'link_card_synced_at' => now()]);
        $post = TimelinePost::factory()->for($this->author)->create(['visibility' => Visibility::Open, 'link_card_id' => $this->card->id, 'link_card_synced_at' => now()]);

        foreach ([
            "/communityTopic/{$topic->id}",
            "/communityEvent/{$event->id}",
            "/timeline/{$post->id}",
        ] as $url) {
            $this->actingAs($this->author)->get($url)->assertOk()->assertSee('A title from the page');
        }
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

    public function test_a_reply_carries_no_card_even_if_its_row_names_one(): void
    {
        // Replies are never synced, so a row like this comes from broken or migrated data. It must
        // not leak: the thread serializer shapes replies and roots alike, and the image URL being
        // unbuildable would leave the title and description exposed on their own.
        config(['openpne.surface_mode' => 'modern_default']);
        $root = TimelinePost::factory()->for($this->author)->create(['visibility' => Visibility::Open]);
        $reply = TimelinePost::factory()->for($this->author)->create([
            'visibility' => Visibility::Open,
            'in_reply_to_id' => $root->id,
            'link_card_id' => $this->card->id,
            'link_card_synced_at' => now(),
        ]);

        $this->assertNull(LinkCardSerializer::card($reply->fresh()));

        $this->actingAs($this->author)->get("/timeline/{$root->id}")
            ->assertInertia(fn ($page) => $page->where('replies.0.linkCard', null)->etc());
    }

    public function test_a_timeline_list_costs_the_same_whatever_the_cards(): void
    {
        // The Classic timeline row is shared by the feed, the profile and three gadgets, so a card
        // read per row would multiply across every one of them.
        $cards = LinkCard::factory()->count(5)->create(['status' => LinkCardStatus::Ok, 'title' => 'A title from the page']);
        foreach ($cards as $card) {
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
}
