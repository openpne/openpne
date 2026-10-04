<?php

namespace Tests\Feature\Upgrade\Term;

use App\Services\TermService;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\TermOverrideUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

/** Runs the compiled sns_term → term_overrides copy. */
class TermOverrideUpgradeSqlTest extends UpgradeSqlTestCase
{
    protected function sourceTables(): array
    {
        return ['sns_term', 'sns_term_translation'];
    }

    /** The stock OpenPNE 3 seed: `community` differs from the OpenPNE 4 default, `friend` matches it. */
    public function test_carries_every_pc_term_verbatim_including_seed_values(): void
    {
        $this->seedTerm('friend', ['ja_JP' => 'フレンド', 'en' => 'friend']);
        $this->seedTerm('community', ['ja_JP' => 'コミュニティ', 'en' => 'community']);

        $this->runUpgrade();

        $this->assertDatabaseHas('term_overrides', ['name' => 'friend', 'locale' => 'ja', 'value' => 'フレンド']);
        $this->assertDatabaseHas('term_overrides', ['name' => 'friend', 'locale' => 'en', 'value' => 'friend']);
        $this->assertDatabaseHas('term_overrides', ['name' => 'community', 'locale' => 'ja', 'value' => 'コミュニティ']);
        $this->assertSame(4, DB::table('term_overrides')->count());

        $service = app(TermService::class);
        $service->clearCache();
        $this->assertSame('コミュニティ', $service->replace('%community%', 'ja'));
        $this->assertSame('グループ', TermService::defaults('ja')['community'], 'the default itself is untouched');
    }

    /** The seed value is a verb; OpenPNE 4 renders the key only as the posting label. */
    public function test_post_activity_is_carried_as_the_posting_label(): void
    {
        $this->seedTerm('post_activity', ['ja_JP' => 'つぶやく', 'en' => 'Tweet']);

        $this->runUpgrade();

        $service = app(TermService::class);
        $service->clearCache();
        app()->setLocale('ja');
        $this->assertSame('つぶやく', __('%Post_activity%'));
        $this->assertSame('Tweet', $service->replace('%Post_activity%', 'en'));
    }

    public function test_skips_mobile_rows_and_unknown_names(): void
    {
        $this->seedTerm('friend', ['ja_JP' => 'ﾌﾚﾝﾄﾞ'], application: 'mobile_frontend');
        $this->seedTerm('some_plugin_term', ['ja_JP' => 'プラグイン']);
        $this->seedTerm('nickname', ['en' => 'handle']);

        $this->runUpgrade();

        $this->assertSame(
            [['name' => 'nickname', 'locale' => 'en', 'value' => 'handle']],
            DB::table('term_overrides')->get()->map(static fn (object $r): array => (array) $r)->all(),
        );
    }

    public function test_folds_lang_to_the_locale_slug_and_keeps_an_unknown_one_verbatim(): void
    {
        $this->seedTerm('topic_free', ['ja_JP' => 'x']); // unknown name: proves the fold test below is not carried by accident
        $this->seedTerm('activity', ['ja_JP' => 'タイムライン', 'en_US' => 'Timeline', 'de_DE' => 'Zeitleiste']);

        $this->runUpgrade();

        $this->assertSame(
            ['de_DE' => 'Zeitleiste', 'en' => 'Timeline', 'ja' => 'タイムライン'],
            DB::table('term_overrides')->where('name', 'activity')->orderBy('locale')->pluck('value', 'locale')->all(),
        );
    }

    public function test_an_empty_or_null_value_is_carried_as_empty(): void
    {
        $this->seedTerm('my_friend', ['ja_JP' => '', 'en' => null]);

        $this->runUpgrade();

        $this->assertDatabaseHas('term_overrides', ['name' => 'my_friend', 'locale' => 'ja', 'value' => '']);
        $this->assertDatabaseHas('term_overrides', ['name' => 'my_friend', 'locale' => 'en', 'value' => '']);
    }

    private function runUpgrade(): void
    {
        DB::statement((new InsertSelectCompiler)->compile(new TermOverrideUpgrade));
    }

    /** @param  array<string, string|null>  $values  lang => value */
    private function seedTerm(string $name, array $values, string $application = 'pc_frontend'): void
    {
        $id = (int) DB::table('sns_term')->insertGetId(['name' => $name, 'application' => $application]);
        foreach ($values as $lang => $value) {
            DB::table('sns_term_translation')->insert(['id' => $id, 'lang' => $lang, 'value' => $value]);
        }
    }
}
