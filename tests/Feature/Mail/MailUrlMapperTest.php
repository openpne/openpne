<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Mail\Template\MailUrlMapper;
use App\Mail\Template\UnsupportedMailTemplateSyntaxException;
use Tests\TestCase;

class MailUrlMapperTest extends TestCase
{
    public function test_resolves_the_community_home_by_id(): void
    {
        $this->assertSame(route('community.show', ['community' => 5]), MailUrlMapper::resolve('@community_home?id=5'));
    }

    public function test_resolves_the_member_profile_by_id(): void
    {
        $this->assertSame(route('member.profile.show', ['member' => 7]), MailUrlMapper::resolve('@member_profile?id=7'));
    }

    public function test_resolves_the_token_routes(): void
    {
        $this->assertSame(url('/register/abc'), MailUrlMapper::resolve('member/register?token=abc'));
        $this->assertSame(url('/member/config/email/confirm/xyz'), MailUrlMapper::resolve('member/configComplete?token=xyz'));
    }

    public function test_a_missing_id_throws(): void
    {
        $this->expectException(UnsupportedMailTemplateSyntaxException::class);
        MailUrlMapper::resolve('@community_home');
    }

    public function test_a_non_numeric_id_throws(): void
    {
        $this->expectException(UnsupportedMailTemplateSyntaxException::class);
        MailUrlMapper::resolve('@member_profile?id=abc');
    }

    public function test_an_unmapped_route_throws(): void
    {
        $this->expectException(UnsupportedMailTemplateSyntaxException::class);
        MailUrlMapper::resolve('community/deleteComment?id=1');
    }
}
