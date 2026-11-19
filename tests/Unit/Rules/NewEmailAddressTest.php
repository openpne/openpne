<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\NewEmailAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NewEmailAddressTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function accepted(): array
    {
        return [
            'plain' => ['taro@example.com'],
            'every atext symbol' => ["a.!#$%&'*+/=?^_`{|}~-z@example.com"],
            'subdomains' => ['taro.yamada+tag@mail.example.co.jp'],
            'punycode domain' => ['user@xn--n8jyh.jp'],
            'uppercase' => ['Taro@Example.COM'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function rejected(): array
    {
        return [
            'Japanese domain' => ['user@れい.jp'],
            'Japanese local part' => ['たろう@example.com'],
            'full-width letters' => ['ｕｓｅｒ@example.com'],
            'accented letter' => ['josé@example.com'],
            // egulias takes an escaped NUL inside quotes; MySQL then compares it equal to the address without it.
            'quoted escaped NUL' => ["\"a\\\x00b\"@example.com"],
            'quoted tab' => ["\"a\tb\"@example.com"],
            'quoted space' => ['"a b"@example.com'],
            'quoted plain' => ['"ab"@example.com'],
            'address literal' => ['user@[127.0.0.1]'],
            'dotless domain' => ['user@localhost'],
            'trailing newline' => ["user@example.com\n"],
            'inner space' => ['a b@example.com'],
            'consecutive dots' => ['taro..yamada@example.com'],
            'leading hyphen in a label' => ['user@-example.com'],
            'underscore in the domain' => ['user@exa_mple.com'],
            'empty' => [''],
        ];
    }

    #[DataProvider('accepted')]
    public function test_it_accepts(string $email): void
    {
        $this->assertTrue(NewEmailAddress::accepts($email));
    }

    #[DataProvider('rejected')]
    public function test_it_rejects(string $email): void
    {
        $this->assertFalse(NewEmailAddress::accepts($email));
    }
}
