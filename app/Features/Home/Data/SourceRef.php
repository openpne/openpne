<?php

declare(strict_types=1);

namespace App\Features\Home\Data;

use App\Features\Home\HomeIssueSection;
use Illuminate\Database\Eloquent\Model;

/**
 * A reference to one featured source, as `alias:id` — the pair the ledger stores, in the one spelling
 * that travels: a command argument, a URL fragment, an array key.
 */
final readonly class SourceRef
{
    public function __construct(public string $type, public int $id) {}

    /**
     * Parse `alias:id`, or null if the text is not one an issue could ever hold.
     *
     * The alias is checked against the sections rather than against the morph map: the map is much
     * wider than what an issue features, so a syntactically fine `bannerImage:1` is still not a
     * source ref. Parsing is the narrowest place to say so.
     */
    public static function tryParse(string $text): ?self
    {
        if (preg_match('/^([a-zA-Z]+):([1-9]\d*)\z/', $text, $matches) !== 1) {
            return null;
        }

        // A number past PHP_INT_MAX casts to PHP_INT_MAX rather than failing, which would silently
        // name a different row than the text did.
        if ((string) (int) $matches[2] !== $matches[2]) {
            return null;
        }

        if (! self::isFeaturable($matches[1])) {
            return null;
        }

        return new self($matches[1], (int) $matches[2]);
    }

    /** The morph alias this model is stored under, paired with its key. */
    public static function of(Model $model): self
    {
        return new self($model->getMorphClass(), (int) $model->getKey());
    }

    public function key(): string
    {
        return $this->type.':'.$this->id;
    }

    /** Whether any section holds sources of this alias. */
    private static function isFeaturable(string $type): bool
    {
        foreach (HomeIssueSection::cases() as $section) {
            if ($section->allowsSource($type)) {
                return true;
            }
        }

        return false;
    }
}
