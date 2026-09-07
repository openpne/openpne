<?php

namespace App\Upgrade\Runner;

use App\Features\Timeline\Announcement;
use ErrorException;

/**
 * The body an OpenPNE 3 template activity becomes: the same line and link Announcement writes for
 * a new record, from the row's `template`, `template_param` (a PHP-serialized array of `%1%`…) and
 * `uri`. A row this cannot render keeps its stored body; the reason is what the pass counts.
 */
final class ActivityTemplateRenderer
{
    public const UNKNOWN_TEMPLATE = 'unknown_template';

    public const BAD_PARAMS = 'bad_params';

    public const NO_LINK = 'no_link';

    public const UNFIT = 'unfit';

    /** OpenPNE 3 template => [the route name its `uri` carries, how many `%n%` params the plugin wrote]. */
    public const TEMPLATES = [
        'diary' => ['@diary_show', 1],
        'community_topic' => ['@communityTopic_show', 2],
        'community_event' => ['@communityEvent_show', 3],
    ];

    public function __construct(private readonly Announcement $announcement) {}

    /** @return array{body: string|null, reason: string|null} */
    public function render(string $template, ?string $serializedParams, ?string $uri, string $locale, int $max): array
    {
        if (! isset(self::TEMPLATES[$template])) {
            return ['body' => null, 'reason' => self::UNKNOWN_TEMPLATE];
        }
        [$route, $arity] = self::TEMPLATES[$template];

        $params = self::params($serializedParams, $arity);
        if ($params === null) {
            return ['body' => null, 'reason' => self::BAD_PARAMS];
        }

        $url = ActivityUriMapper::resolve($uri, $route);
        if ($url === null) {
            return ['body' => null, 'reason' => self::NO_LINK];
        }

        $body = match ($template) {
            'diary' => $this->announcement->diary($params[1], $url, $locale, $max),
            'community_topic' => $this->announcement->topic($params[1], $params[2], $url, $locale, $max),
            'community_event' => $this->announcement->event($params[1], $params[2], trim($params[3]), $url, $locale, $max),
        };

        return ['body' => $body, 'reason' => $body === null ? self::UNFIT : null];
    }

    /**
     * `%1%` => 'x' as the 1-based list of exactly $arity strings, or null when the value is not that.
     * Objects are refused and a malformed string is a notice, which the handler turns into null.
     *
     * @return array<int, string>|null
     */
    private static function params(?string $serialized, int $arity): ?array
    {
        if ($serialized === null || $serialized === '') {
            return null;
        }

        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });

        try {
            $decoded = unserialize($serialized, ['allowed_classes' => false]);
        } catch (ErrorException) {
            return null;
        } finally {
            restore_error_handler();
        }

        if (! is_array($decoded)) {
            return null;
        }

        $params = [];
        foreach ($decoded as $key => $value) {
            if (! is_scalar($value) || ! preg_match('/^%([1-9])%$/', (string) $key, $m) || (int) $m[1] > $arity) {
                return null;
            }
            $params[(int) $m[1]] = (string) $value;
        }

        return count($params) === $arity ? $params : null;
    }
}
