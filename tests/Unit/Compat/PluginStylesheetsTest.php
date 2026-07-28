<?php

declare(strict_types=1);

namespace Tests\Unit\Compat;

use App\Compat\PluginStylesheets;
use PHPUnit\Framework\TestCase;

/**
 * The per-module stylesheet map against the OpenPNE 3 view.yml it ports: which screens get a
 * plugin stylesheet, and — just as load-bearing — which do not, since each file also restyles
 * kinds shared with other modules.
 */
class PluginStylesheetsTest extends TestCase
{
    /**
     * The vendored bytes, locked. The files are served to themes and customer CSS as the URLs
     * OpenPNE 3 served them from, so an edit here is a silent compatibility break: any fix
     * belongs in a stylesheet of our own, not in the vendored copy.
     */
    public function test_the_vendored_files_are_the_openpne3_bytes(): void
    {
        $this->assertSame([
            'opDiaryPlugin/css/diary.css' => '82a8852e045e5891b538528c38559e7d',
            'opCommunityTopicPlugin/css/communityTopic.css' => 'a222acbbda97c3db931c15bd17d9c798',
            'opMessagePlugin/css/message.css' => '4c62db3435618cd0299727253ccd4d9e',
            // The message list's row status icons (listSuccess.php): unopened / opened / sent /
            // replied. message.css does not reference them — the templates do, by path.
            'opMessagePlugin/images/icon_mail_1.gif' => '3d40a300272ec2812ae4ca958b7038d9',
            'opMessagePlugin/images/icon_mail_2.gif' => 'f20567b31473c2d80f0b8c2e225307f7',
            'opMessagePlugin/images/icon_mail_3.gif' => '1c118e37db2cee0417d41d75de81757f',
            'opMessagePlugin/images/icon_mail_4.gif' => '251cde24c20378a312056c26635818da',
            // communityTopic.css's only url(): ../../images/icon_2.gif from the css directory,
            // which is why the plugins keep OpenPNE 3's web-root-relative layout under public/.
            'images/icon_2.gif' => '5eeff8dd69a4d7606d8455fac953eb8a',
            // The Classic shell's flash alertBox icon (OpenPNE 3 `_partsAlertBox.php`).
            'images/icon_alert.gif' => '05daef255575c91468b475c2915a9a3c',
        ], $this->md5sOf([
            'opDiaryPlugin/css/diary.css',
            'opCommunityTopicPlugin/css/communityTopic.css',
            'opMessagePlugin/css/message.css',
            'opMessagePlugin/images/icon_mail_1.gif',
            'opMessagePlugin/images/icon_mail_2.gif',
            'opMessagePlugin/images/icon_mail_3.gif',
            'opMessagePlugin/images/icon_mail_4.gif',
            'images/icon_2.gif',
            'images/icon_alert.gif',
        ]));
    }

    public function test_maps_each_module_to_the_stylesheet_its_view_yml_declares(): void
    {
        // opDiaryPlugin diary/ + diaryComment/ config/view.yml
        $this->assertSame('opDiaryPlugin/css/diary.css', PluginStylesheets::forRoute('diary.show'));
        $this->assertSame('opDiaryPlugin/css/diary.css', PluginStylesheets::forRoute('diary.list'));
        $this->assertSame('opDiaryPlugin/css/diary.css', PluginStylesheets::forRoute('diary.comment.delete.show'));

        // opCommunityTopicPlugin communityTopic/ + communityTopicComment/ + communityEvent/ +
        // communityEventComment/ config/view.yml — one stylesheet across all four modules.
        $this->assertSame('opCommunityTopicPlugin/css/communityTopic.css', PluginStylesheets::forRoute('communityTopic.show'));
        $this->assertSame('opCommunityTopicPlugin/css/communityTopic.css', PluginStylesheets::forRoute('communityTopic.index'));
        $this->assertSame('opCommunityTopicPlugin/css/communityTopic.css', PluginStylesheets::forRoute('communityTopic.comment.delete.show'));
        $this->assertSame('opCommunityTopicPlugin/css/communityTopic.css', PluginStylesheets::forRoute('communityEvent.show'));
        $this->assertSame('opCommunityTopicPlugin/css/communityTopic.css', PluginStylesheets::forRoute('communityEvent.comment.delete.show'));

        // opMessagePlugin message/config/view.yml
        $this->assertSame('opMessagePlugin/css/message.css', PluginStylesheets::forRoute('message.receive'));
        $this->assertSame('opMessagePlugin/css/message.css', PluginStylesheets::forRoute('message.receive.show'));
    }

    public function test_modules_that_declare_no_stylesheet_get_none(): void
    {
        // The community module embeds the topic and event components on its home without loading
        // communityTopic.css — its view.yml declares no stylesheet, only the customize entries.
        $this->assertNull(PluginStylesheets::forRoute('community.show'));
        $this->assertNull(PluginStylesheets::forRoute('member.profile.show'));
        $this->assertNull(PluginStylesheets::forRoute('home'));

        // A route no parity renders (or none at all) resolves to no stylesheet rather than failing.
        $this->assertNull(PluginStylesheets::forRoute('diary.store'));
        $this->assertNull(PluginStylesheets::forRoute(null));
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, string>
     */
    private function md5sOf(array $paths): array
    {
        $public = dirname(__DIR__, 3).'/public/';

        return array_combine($paths, array_map(
            function (string $path) use ($public): string {
                $this->assertFileExists($public.$path);

                return md5_file($public.$path);
            },
            $paths,
        ));
    }
}
