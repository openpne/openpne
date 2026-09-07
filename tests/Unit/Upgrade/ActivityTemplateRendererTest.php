<?php

namespace Tests\Unit\Upgrade;

use App\Upgrade\Runner\ActivityTemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ActivityTemplateRendererTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://sns.example');
    }

    public function test_renders_the_three_openpne3_templates_from_their_serialized_params(): void
    {
        $renderer = app(ActivityTemplateRenderer::class);

        $this->assertSame(
            ['body' => "[Diary] Hello\nhttp://sns.example/diary/3", 'reason' => null],
            $renderer->render('diary', serialize(['%1%' => 'Hello']), '@diary_show?id=3', 'en', 140),
        );
        $this->assertSame(
            ['body' => "[Group topic] Marathon (Runners)\nhttp://sns.example/topics/4", 'reason' => null],
            $renderer->render('community_topic', serialize(['%1%' => 'Runners', '%2%' => 'Marathon']), '@communityTopic_show?id=4', 'en', 140),
        );
        $this->assertSame(
            ['body' => "[Group event] Picnic (Runners, 2015年05月06日 13:00)\nhttp://sns.example/events/5", 'reason' => null],
            $renderer->render('community_event', serialize(['%1%' => 'Runners', '%2%' => 'Picnic', '%3%' => '2015年05月06日 13:00']), '@communityEvent_show?id=5', 'en', 140),
        );
        // OpenPNE 3 passed an empty open when the event had no date.
        $this->assertSame(
            ['body' => "[Group event] Picnic (Runners)\nhttp://sns.example/events/5", 'reason' => null],
            $renderer->render('community_event', serialize(['%1%' => 'Runners', '%2%' => 'Picnic', '%3%' => '']), '@communityEvent_show?id=5', 'en', 140),
        );
    }

    public function test_the_site_locale_and_the_cap_are_the_callers(): void
    {
        $renderer = app(ActivityTemplateRenderer::class);

        $this->assertSame("[日記] こんにちは\nhttp://sns.example/diary/3", $renderer->render('diary', serialize(['%1%' => 'こんにちは']), '@diary_show?id=3', 'ja', 140)['body']);
        $body = $renderer->render('diary', serialize(['%1%' => str_repeat('x', 300)]), '@diary_show?id=3', 'en', 140)['body'];
        $this->assertSame(140, mb_strlen($body));
        $this->assertSame(5000, mb_strlen($renderer->render('diary', serialize(['%1%' => str_repeat('x', 6000)]), '@diary_show?id=3', 'en', 5000)['body']));
    }

    public function test_a_row_it_cannot_render_says_why(): void
    {
        $renderer = app(ActivityTemplateRenderer::class);
        $params = serialize(['%1%' => 'x']);

        $this->assertSame(ActivityTemplateRenderer::UNKNOWN_TEMPLATE, $renderer->render('friend_link', $params, '@diary_show?id=3', 'en', 140)['reason']);
        $this->assertSame(ActivityTemplateRenderer::NO_LINK, $renderer->render('diary', $params, '@member_profile?id=3', 'en', 140)['reason']);
        $this->assertSame(ActivityTemplateRenderer::NO_LINK, $renderer->render('diary', $params, '@diary_show?id=abc', 'en', 140)['reason']);
        $this->assertSame(ActivityTemplateRenderer::NO_LINK, $renderer->render('diary', $params, null, 'en', 140)['reason']);
        $this->assertSame(ActivityTemplateRenderer::BAD_PARAMS, $renderer->render('diary', 'not serialized', '@diary_show?id=3', 'en', 140)['reason']);
        $this->assertSame(ActivityTemplateRenderer::BAD_PARAMS, $renderer->render('diary', serialize('a string'), '@diary_show?id=3', 'en', 140)['reason']);
        $this->assertSame(ActivityTemplateRenderer::BAD_PARAMS, $renderer->render('diary', serialize(['title' => 'x']), '@diary_show?id=3', 'en', 140)['reason']);
        $this->assertSame(ActivityTemplateRenderer::BAD_PARAMS, $renderer->render('diary', null, '@diary_show?id=3', 'en', 140)['reason']);
        // An object payload is refused rather than instantiated.
        $this->assertSame(ActivityTemplateRenderer::BAD_PARAMS, $renderer->render('diary', 'O:8:"stdClass":0:{}', '@diary_show?id=3', 'en', 140)['reason']);

        URL::forceRootUrl('http://'.str_repeat('h', 140).'.example');
        $this->assertSame(ActivityTemplateRenderer::UNFIT, $renderer->render('diary', $params, '@diary_show?id=3', 'en', 140)['reason']);
    }
}
