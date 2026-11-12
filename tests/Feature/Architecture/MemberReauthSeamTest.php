<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Auth\Reauth\ReauthPurpose;
use App\Auth\Reauth\RequiresReauth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * A member's password is checked in the files listed here and nowhere else, so a new step-up cannot
 * skip the seam's second factor and locked-row verdict (docs/internals/security.md,
 * "Member re-authentication").
 */
class MemberReauthSeamTest extends TestCase
{
    private const PASSWORD_RULE = ['app/Auth/Reauth/RequiresReauth.php'];

    private const HASH_CHECK = [
        'app/Actions/Fortify/AuthenticateMember.php',
        'app/Auth/AdminMfaPasswordReauth.php',
        'app/Auth/PasswordScheme.php',
        'app/Auth/Reauth/VerifiesReauthProof.php',
        'app/Features/Member/Actions/ConsumeMfaReset.php',
    ];

    public function test_the_member_password_rule_is_written_by_the_seam_alone(): void
    {
        $this->assertSame(self::PASSWORD_RULE, $this->filesMatching('/[\'"]current_password:member[\'"]/'));
    }

    public function test_a_raw_password_check_stays_in_the_listed_files(): void
    {
        $this->assertSame(self::HASH_CHECK, $this->filesMatching('/Hash::(driver\([^)]*\)->)?check\(|password_verify\(|->check\(\$[a-zA-Z>-]*[pP]assword/'));
    }

    public function test_every_purpose_is_asked_by_a_request(): void
    {
        $asked = [];
        foreach ((new Finder)->files()->in(app_path('Http/Requests'))->name('*.php') as $file) {
            $class = 'App\\Http\\Requests\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            if (is_subclass_of($class, FormRequest::class) && in_array(RequiresReauth::class, class_uses_recursive($class), true)) {
                $asked[] = (new $class)->reauthPurpose();
            }
        }

        $missing = array_udiff(ReauthPurpose::cases(), $asked, fn (ReauthPurpose $a, ReauthPurpose $b): int => $a->value <=> $b->value);
        $this->assertSame([], array_map(fn (ReauthPurpose $p): string => $p->value, array_values($missing)));
    }

    public function test_every_route_that_asks_for_a_proof_is_throttled(): void
    {
        $unthrottled = [];
        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction('uses');
            if (! is_string($action) || ! str_contains($action, '@')) {
                continue;
            }
            [$class, $method] = explode('@', $action);
            foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()
                    && in_array(RequiresReauth::class, class_uses_recursive($type->getName()), true)
                    && ! collect($route->gatherMiddleware())->contains(fn ($m): bool => is_string($m) && str_starts_with($m, 'throttle:'))) {
                    $unthrottled[] = $route->uri();
                }
            }
        }

        $this->assertSame([], $unthrottled);
    }

    /** @return list<string> */
    private function filesMatching(string $pattern): array
    {
        $found = [];
        foreach ((new Finder)->files()->in(base_path('app'))->name('*.php')->contains($pattern) as $file) {
            $found[] = str_replace(base_path().'/', '', $file->getRealPath());
        }
        sort($found);

        return $found;
    }
}
