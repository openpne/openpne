<?php

namespace Tests\Unit\Support;

use App\Support\PreferenceKey;
use App\Support\Visibility;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PreferenceKeyTest extends TestCase
{
    public function test_every_key_resolves_from_its_op3_source_name(): void
    {
        foreach (PreferenceKey::cases() as $key) {
            $this->assertSame($key, PreferenceKey::fromOp3SourceName($key->op3SourceName()));
        }

        $this->assertNull(PreferenceKey::fromOp3SourceName('some_custom_config'));
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
}
