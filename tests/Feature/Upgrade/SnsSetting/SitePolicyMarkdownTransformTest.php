<?php

declare(strict_types=1);

namespace Tests\Feature\Upgrade\SnsSetting;

use App\Models\UpgradeState;
use App\Upgrade\Runner\SitePolicyMarkdownTransform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The post-walk pass that rewrites the imported policy bodies as Markdown. The pass is not
 * idempotent, so the tests that matter are about when it runs a second time: after a completed run
 * (never) and after a crashed one (exactly once, from the original text).
 */
class SitePolicyMarkdownTransformTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $out = [];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('sns_settings')->where('key', 'user_agreement')->delete();
        DB::table('sns_settings')->where('key', 'privacy_policy')->delete();
    }

    public function test_converts_both_policy_bodies(): void
    {
        $this->seedSetting('user_agreement', "第1条\n1. 会員とは…");
        $this->seedSetting('privacy_policy', '<h2>取得する情報</h2>');

        $this->assertTrue($this->runPass());

        $this->assertSame("第1条\n1\\. 会員とは…", $this->stored('user_agreement'));
        $this->assertSame('## 取得する情報', $this->stored('privacy_policy'));
        $this->assertSame(2, (int) UpgradeState::where('step_key', 'site_policy_markdown')->value('rows_affected'));
        $this->assertStringContainsString('review it under', implode("\n", $this->out));
    }

    public function test_leaves_an_empty_body_alone(): void
    {
        $this->seedSetting('user_agreement', '');
        $this->seedSetting('privacy_policy', '# 見出しではない');

        $this->assertTrue($this->runPass());

        $this->assertSame('', $this->stored('user_agreement'));
        // rows_affected counts bodies that changed, so the untouched one is not in it.
        $this->assertSame(1, (int) UpgradeState::where('step_key', 'site_policy_markdown')->value('rows_affected'));
    }

    public function test_skips_when_the_run_does_not_own_sns_settings(): void
    {
        $this->seedSetting('user_agreement', '# 見出しではない');

        $this->assertTrue((new SitePolicyMarkdownTransform)->run(['members'], $this->collector()));

        $this->assertSame('# 見出しではない', $this->stored('user_agreement'));
        $this->assertNull(UpgradeState::where('step_key', 'site_policy_markdown')->first());
    }

    public function test_a_completed_checkpoint_stops_a_second_escape(): void
    {
        $this->seedSetting('user_agreement', '# 見出しではない');
        $this->runPass();
        $once = $this->stored('user_agreement');

        $this->assertTrue($this->runPass());

        $this->assertSame($once, $this->stored('user_agreement'));
        $this->assertStringContainsString('already completed', implode("\n", $this->out));
    }

    public function test_a_rewrite_that_outgrows_the_column_fails_the_pass(): void
    {
        // Every character escapes to two, so the rewrite doubles past the 65535-byte column.
        $this->seedSetting('user_agreement', str_repeat('*', 40_000));
        $this->seedSetting('privacy_policy', '# 見出しではない');

        $this->assertFalse($this->runPass());

        $this->assertSame(str_repeat('*', 40_000), $this->stored('user_agreement'));
        $this->assertSame('# 見出しではない', $this->stored('privacy_policy'));

        $state = UpgradeState::where('step_key', 'site_policy_markdown')->first();
        $this->assertSame(UpgradeState::STATUS_FAILED, $state->status);
        $this->assertStringContainsString('user_agreement', (string) $state->error);
    }

    public function test_a_crash_mid_pass_rolls_back_and_the_retry_converts_once(): void
    {
        $this->seedSetting('user_agreement', '# 見出しではない');
        $this->seedSetting('privacy_policy', '- 箇条書きではない');

        // Fail after the first row is written: the checkpoint shares the transaction, so the rewrite
        // must roll back with it rather than leave converted rows behind an unfinished checkpoint.
        $crash = true;
        DB::listen(function ($query) use (&$crash): void {
            if ($crash && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'sns_settings')) {
                throw new RuntimeException('crash');
            }
        });

        $this->assertFalse($this->runPass());

        $this->assertSame('# 見出しではない', $this->stored('user_agreement'));
        $this->assertSame('- 箇条書きではない', $this->stored('privacy_policy'));
        $this->assertSame(UpgradeState::STATUS_FAILED, UpgradeState::where('step_key', 'site_policy_markdown')->value('status'));

        $crash = false;
        $this->assertTrue($this->runPass());

        $this->assertSame('\\# 見出しではない', $this->stored('user_agreement'));
        $this->assertSame('\\- 箇条書きではない', $this->stored('privacy_policy'));
    }

    private function runPass(): bool
    {
        return (new SitePolicyMarkdownTransform)->run(['sns_settings'], $this->collector());
    }

    private function collector(): callable
    {
        return function (string $line): void {
            $this->out[] = $line;
        };
    }

    private function seedSetting(string $key, string $value): void
    {
        DB::table('sns_settings')->updateOrInsert(['key' => $key], ['value' => $value]);
    }

    private function stored(string $key): ?string
    {
        return DB::table('sns_settings')->where('key', $key)->value('value');
    }
}
