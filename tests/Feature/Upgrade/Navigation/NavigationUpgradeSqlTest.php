<?php

namespace Tests\Feature\Upgrade\Navigation;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\NavigationTranslationUpgrade;
use App\Upgrade\Steps\NavigationUpgrade;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled navigation INSERT...SELECT against the real OpenPNE 3 `navigation` +
 * `navigation_translation` DDL, exercising the uri normalization CASE (route-name / module-action /
 * already-URL / unresolved) and the PC-type filter.
 *
 * MySQL only, like the other upgrade SQL tests: the source DDL (TEXT, utf8mb3, DATETIME) and the
 * set-based copy are MySQL features. DatabaseMigrations because creating the source tables is DDL.
 */
class NavigationUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        $this->createSourceTables();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `navigation_translation`');
            DB::statement('DROP TABLE IF EXISTS `navigation`');
        }

        parent::tearDown();
    }

    public function test_keeps_an_already_formed_url(): void
    {
        $this->seedNav(1, 'secure_global', '/custom/path');
        $this->seedNav(2, 'secure_global', 'https://example.com/help');

        $this->runUpgrade();

        $this->assertSame('/custom/path', $this->upgradedUri(1));
        $this->assertSame('https://example.com/help', $this->upgradedUri(2));
    }

    public function test_resolves_a_route_name_token(): void
    {
        $this->seedNav(1, 'secure_global', '@homepage');
        $this->seedNav(2, 'secure_global', '@member_search');
        $this->seedNav(3, 'secure_global', '@member_logout');

        $this->runUpgrade();

        $this->assertSame('/', $this->upgradedUri(1));
        $this->assertSame('/member/search', $this->upgradedUri(2));
        $this->assertSame('/logout', $this->upgradedUri(3));
    }

    public function test_keeps_an_id_bearing_route_name(): void
    {
        // A route name fixes its URL, so @member_profile normalizes the same in any context — even a
        // global type. The renderer (not the upgrade) hides such a row where no subject id exists.
        $this->seedNav(1, 'friend', '@member_profile');
        $this->seedNav(2, 'secure_global', '@member_profile');

        $this->runUpgrade();

        $this->assertSame('/member/:id', $this->upgradedUri(1));
        $this->assertSame('/member/:id', $this->upgradedUri(2));
    }

    public function test_module_action_is_type_aware(): void
    {
        $this->seedNav(1, 'default', 'diary/listMember');
        $this->seedNav(2, 'friend', 'diary/listMember');
        $this->seedNav(3, 'secure_global', 'diary/index');

        $this->runUpgrade();

        $this->assertSame('/diary/listMember', $this->upgradedUri(1)); // id-less context
        $this->assertSame('/diary/listMember/:id', $this->upgradedUri(2)); // friend context
        $this->assertSame('/diary', $this->upgradedUri(3));
    }

    public function test_keeps_an_unresolved_value_verbatim(): void
    {
        $this->seedNav(1, 'secure_global', '@unported_plugin_route'); // a route name not in the inventory
        $this->seedNav(2, 'friend', 'message/sendToFriend'); // module/action whose compose target is the write surface

        $this->runUpgrade();

        $this->assertSame('@unported_plugin_route', $this->upgradedUri(1));
        $this->assertSame('message/sendToFriend', $this->upgradedUri(2));
    }

    public function test_resolves_ported_message_links(): void
    {
        // OpenPNE 3 links the inbox by route name (@receiveList, smartphone nav) and by
        // module/action (message/index, the PC default nav). Both normalize to the URL now that
        // message is ported — leaving them verbatim would let NavigationUri hide the link.
        $this->seedNav(1, 'secure_global', '@receiveList');
        $this->seedNav(2, 'default', 'message/index');

        $this->runUpgrade();

        $this->assertSame('/message/receiveList', $this->upgradedUri(1));
        $this->assertSame('/message/receiveList', $this->upgradedUri(2));
    }

    public function test_carries_the_original_in_source_uri(): void
    {
        $this->seedNav(1, 'secure_global', '@homepage');

        $this->runUpgrade();

        $this->assertSame('@homepage', DB::table('navigations')->where('id', 1)->value('source_uri'));
    }

    public function test_excludes_non_pc_types_and_their_translations(): void
    {
        $this->seedNav(1, 'secure_global', '@homepage');
        $this->seedNavTranslation(1, 'en', 'My Home');
        $this->seedNav(2, 'mobile_home', '@homepage');
        $this->seedNavTranslation(2, 'en', 'Mobile Home');

        $this->runUpgrade();

        $this->assertDatabaseHas('navigations', ['id' => 1]);
        $this->assertDatabaseMissing('navigations', ['id' => 2]);
        $this->assertDatabaseHas('navigation_translations', ['id' => 1, 'lang' => 'en', 'caption' => 'My Home']);
        $this->assertDatabaseMissing('navigation_translations', ['id' => 2]);
    }

    private function createSourceTables(): void
    {
        DB::statement('DROP TABLE IF EXISTS `navigation_translation`');
        DB::statement('DROP TABLE IF EXISTS `navigation`');
        DB::statement(SourceSchema::default()->createStatement('navigation', withoutForeignKeys: true));
        DB::statement(SourceSchema::default()->createStatement('navigation_translation', withoutForeignKeys: true));
    }

    private function runUpgrade(): void
    {
        $compiler = new InsertSelectCompiler;
        DB::statement($compiler->compile(new NavigationUpgrade));
        DB::statement($compiler->compile(new NavigationTranslationUpgrade));
    }

    private function seedNav(int $id, string $type, string $uri, ?int $sort = 0): void
    {
        DB::table('navigation')->insert([
            'id' => $id,
            'type' => $type,
            'uri' => $uri,
            'sort_order' => $sort,
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);
    }

    private function seedNavTranslation(int $id, string $lang, string $caption): void
    {
        DB::table('navigation_translation')->insert(['id' => $id, 'lang' => $lang, 'caption' => $caption]);
    }

    private function upgradedUri(int $id): ?string
    {
        return DB::table('navigations')->where('id', $id)->value('uri');
    }
}
