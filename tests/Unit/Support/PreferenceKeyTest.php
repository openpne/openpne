<?php

namespace Tests\Unit\Support;

use App\Support\PreferenceKey;
use App\Support\Surface;
use App\Support\Visibility;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PreferenceKeyTest extends TestCase
{
    public function test_every_upgradable_key_resolves_from_its_op3_source_name(): void
    {
        // upgradableCases() are exactly the keys with a non-null op3SourceName, so each round-trips.
        foreach (PreferenceKey::upgradableCases() as $key) {
            $this->assertNotNull($key->op3SourceName());
            $this->assertSame($key, PreferenceKey::fromOp3SourceName($key->op3SourceName()));
        }

        $this->assertNull(PreferenceKey::fromOp3SourceName('some_custom_config'));
    }

    public function test_native_keys_have_no_op3_source_and_are_not_upgradable(): void
    {
        // PreferredSurface is OpenPNE 4-native: no member_config source, so it never enters the upgrade.
        $this->assertNull(PreferenceKey::PreferredSurface->op3SourceName());
        $this->assertNotContains(PreferenceKey::PreferredSurface, PreferenceKey::upgradableCases());
    }

    public function test_preferred_surface_is_tri_state(): void
    {
        // Absent row (and any corrupted value) means "no member choice" — null, deferring to the
        // SurfaceResolver fallback — never a concrete surface.
        $this->assertNull(PreferenceKey::PreferredSurface->default());
        $this->assertNull(PreferenceKey::PreferredSurface->decode(null));
        $this->assertNull(PreferenceKey::PreferredSurface->decode('nonsense'));

        $this->assertSame(Surface::Classic, PreferenceKey::PreferredSurface->decode('classic'));
        $this->assertSame(Surface::Modern, PreferenceKey::PreferredSurface->decode('modern'));
        $this->assertSame('modern', PreferenceKey::PreferredSurface->encode(Surface::Modern));
    }

    public function test_decode_uses_the_key_default_when_absent_or_invalid(): void
    {
        $this->assertSame(Visibility::Members, PreferenceKey::DiaryDefaultVisibility->decode(null));
        $this->assertSame(Visibility::Private, PreferenceKey::AgeVisibility->decode(null));
        // Out-of-range stored value falls back rather than throwing.
        $this->assertSame(Visibility::Members, PreferenceKey::DiaryDefaultVisibility->decode('99'));
    }

    public function test_decode_reads_a_stored_value(): void
    {
        $this->assertSame(Visibility::Friends, PreferenceKey::DiaryDefaultVisibility->decode('2'));
        // A real stored '0' is the explicit Open choice and round-trips.
        $this->assertSame(Visibility::Open, PreferenceKey::DiaryDefaultVisibility->decode('0'));
    }

    public function test_decode_rejects_non_digit_values_without_failing_open(): void
    {
        // (int) '' and (int) 'foo' are 0 in PHP = Visibility::Open; a corrupted value must use
        // the key default, never the least-restrictive audience.
        $this->assertSame(Visibility::Members, PreferenceKey::DiaryDefaultVisibility->decode(''));
        $this->assertSame(Visibility::Members, PreferenceKey::DiaryDefaultVisibility->decode('foo'));
        $this->assertSame(Visibility::Private, PreferenceKey::AgeVisibility->decode('bar'));
    }

    public function test_encode_decode_round_trips_every_visibility(): void
    {
        foreach (Visibility::cases() as $visibility) {
            $encoded = PreferenceKey::DiaryDefaultVisibility->encode($visibility);
            $this->assertSame($visibility, PreferenceKey::DiaryDefaultVisibility->decode($encoded));
        }
    }

    public function test_coerce_accepts_enum_int_and_string(): void
    {
        $this->assertSame(Visibility::Friends, PreferenceKey::DiaryDefaultVisibility->coerce(Visibility::Friends));
        $this->assertSame(Visibility::Friends, PreferenceKey::DiaryDefaultVisibility->coerce(2));
        $this->assertSame(Visibility::Friends, PreferenceKey::DiaryDefaultVisibility->coerce('2'));
    }

    public function test_coerce_rejects_an_out_of_range_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PreferenceKey::DiaryDefaultVisibility->coerce(99);
    }

    public function test_coerce_rejects_a_non_digit_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PreferenceKey::DiaryDefaultVisibility->coerce('foo');
    }
}
