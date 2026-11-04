<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\MailFromAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MailFromAddressTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function addresses(): iterable
    {
        yield 'shipped placeholder' => ['hello@example.com', false];
        yield 'placeholder, other case' => ['noreply@Example.ORG', false];
        yield 'placeholder subdomain' => ['noreply@mail.example.net', false];
        yield 'empty' => ['', false];
        yield 'no domain' => ['noreply', false];
        yield 'real domain' => ['noreply@openpne.example.test', true];
        yield 'domain merely containing example' => ['noreply@myexample.com', true];
    }

    #[Test]
    #[DataProvider('addresses')]
    public function it_tells_the_documentation_domains_from_a_configured_sender(string $address, bool $configured): void
    {
        $this->assertSame($configured, MailFromAddress::isConfigured($address));
    }
}
