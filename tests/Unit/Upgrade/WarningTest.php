<?php

namespace Tests\Unit\Upgrade;

use App\Upgrade\Runner\ActivityTemplateRenderer;
use App\Upgrade\Runner\ActivityTemplateTransform;
use App\Upgrade\Runner\Warning;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class WarningTest extends TestCase
{
    /** The registry as the docs publish it: a flipped category or a renamed code is red here. */
    public static function registry(): array
    {
        return [
            ['W-ABSENT-PLUGIN', 'dropped'],
            ['W-UNKNOWN-NAME', 'unknown'],
            ['W-SETTING-IN-ENV', 'env'],
            ['W-SETTING-UNREADABLE', 'env'],
            ['W-SETTING-NULL', 'review'],
            ['W-MAIL-INERT-LOCALE', 'review'],
            ['W-MAIL-BROKEN', 'review'],
            ['W-MAIL-DEGRADED', 'review'],
            ['W-ACTIVITY-DEEP-REPLY', 'rewritten'],
            ['W-ACTIVITY-DANGLING-REPLY', 'rewritten'],
            ['W-ACTIVITY-CROSS-SCOPE-REPLY', 'rewritten'],
            ['W-ACTIVITY-UNRESOLVABLE-REPLY', 'dropped'],
            ['W-ACTIVITY-OTHER-SCOPE', 'dropped'],
            ['W-ACTIVITY-ORPHAN-GROUP-THREAD', 'dropped'],
            ['W-ACTIVITY-NON-MEMBERS-GROUP-THREAD', 'dropped'],
            ['W-ACTIVITY-URI-ONLY-IMAGE', 'dropped'],
            ['W-ACTIVITY-TEMPLATE-RENDERED', 'rewritten'],
            ['W-ACTIVITY-TEMPLATE-DROPPED', 'dropped'],
            ['W-ACTIVITY-TEMPLATE-UNKNOWN', 'review'],
            ['W-LIKE-UNMIGRATED-ACTIVITY', 'dropped'],
            ['W-LIKE-GONE-TARGET', 'dropped'],
            ['W-LIKE-UNINSTALLED-TARGET', 'dropped'],
            ['W-LIKE-UNKNOWN-TABLE', 'unknown'],
            ['W-UPLOAD-OWNERLESS', 'review'],
            ['W-ACTIVITY-TEMPLATE-KEPT-UNKNOWN', 'review'],
            ['W-ACTIVITY-TEMPLATE-KEPT-BAD-PARAMS', 'review'],
            ['W-ACTIVITY-TEMPLATE-KEPT-NO-LINK', 'review'],
            ['W-ACTIVITY-TEMPLATE-KEPT-UNFIT', 'review'],
            ['W-THEME-UNKNOWN-PACKAGE', 'review'],
            ['W-THEME-MISSING-PACKAGE', 'review'],
        ];
    }

    #[DataProvider('registry')]
    public function test_each_code_has_its_published_category(string $code, string $category): void
    {
        $this->assertSame($category, (new Warning($code, 'm'))->category());
    }

    public function test_the_registry_holds_exactly_the_published_codes(): void
    {
        $this->assertSame(array_column(self::registry(), 0), array_keys(Warning::CATEGORY_OF));
    }

    public function test_the_line_carries_the_code_and_an_unregistered_code_is_refused(): void
    {
        $this->assertSame('W-UNKNOWN-NAME: three rows', (new Warning('W-UNKNOWN-NAME', 'three rows', 3))->line());

        $this->expectException(InvalidArgumentException::class);
        new Warning('W-NOT-A-CODE', 'm');
    }

    public function test_every_origin_constant_is_registered_unique_well_formed_and_documented(): void
    {
        $guide = file_get_contents(dirname(__DIR__, 3).'/docs/upgrading-from-openpne3.md');
        $seen = [];

        foreach (glob(dirname(__DIR__, 3).'/app/Upgrade/Runner/*.php') as $file) {
            $class = 'App\\Upgrade\\Runner\\'.basename($file, '.php');
            foreach ((new ReflectionClass($class))->getConstants() as $name => $value) {
                if ($class === Warning::class || ! str_starts_with($name, 'W_')) {
                    continue;
                }
                $this->assertMatchesRegularExpression(Warning::CODE_PATTERN, $value, "{$class}::{$name}");
                $this->assertArrayHasKey($value, Warning::CATEGORY_OF, "{$class}::{$name} is not registered");
                $this->assertArrayNotHasKey($value, $seen, "{$value} is declared twice");
                $this->assertStringContainsString("`{$value}`", $guide, "{$value} is not in the operator guide");
                $seen[$value] = "{$class}::{$name}";
            }
        }

        $this->assertSame([], array_diff(array_keys(Warning::CATEGORY_OF), array_keys($seen)), 'a registered code no origin declares');
    }

    public function test_every_kept_reason_has_its_own_code(): void
    {
        $reasons = [ActivityTemplateRenderer::UNKNOWN_TEMPLATE, ActivityTemplateRenderer::BAD_PARAMS, ActivityTemplateRenderer::NO_LINK, ActivityTemplateRenderer::UNFIT];
        $codes = array_map(static fn (string $reason): string => ActivityTemplateTransform::keptWarning('timeline_posts', $reason, 1)->code, $reasons);

        $this->assertSame([
            ActivityTemplateTransform::W_KEPT_UNKNOWN,
            ActivityTemplateTransform::W_KEPT_BAD_PARAMS,
            ActivityTemplateTransform::W_KEPT_NO_LINK,
            ActivityTemplateTransform::W_KEPT_UNFIT,
        ], $codes);
        $this->assertSame(1, ActivityTemplateTransform::keptWarning('timeline_posts', ActivityTemplateRenderer::UNFIT, 1)->count);

        $this->expectException(\UnhandledMatchError::class);
        ActivityTemplateTransform::keptWarning('timeline_posts', 'stale-reason', 1);
    }
}
