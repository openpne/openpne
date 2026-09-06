<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\PhoneClient;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneClientTest extends TestCase
{
    private const IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    private const IPHONE_CHROME = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/125.0.6422.80 Mobile/15E148 Safari/604.1';

    private const ANDROID_CHROME_PHONE = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Mobile Safari/537.36';

    private const ANDROID_FIREFOX_PHONE = 'Mozilla/5.0 (Android 14; Mobile; rv:126.0) Gecko/126.0 Firefox/126.0';

    private const ANDROID_CHROME_TABLET = 'Mozilla/5.0 (Linux; Android 14; SM-X910) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    private const IPAD_LEGACY = 'Mozilla/5.0 (iPad; CPU OS 12_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/12.1 Mobile/15E148 Safari/604.1';

    private const IPAD_DESKTOP_CLASS = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';

    private const DESKTOP_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    /** @return array<string, array{string|null, string|null, bool}> */
    public static function clients(): array
    {
        return [
            'iPhone Safari' => [self::IPHONE_SAFARI, null, true],
            'iPhone Chrome' => [self::IPHONE_CHROME, null, true],
            'Android Chrome phone' => [self::ANDROID_CHROME_PHONE, null, true],
            'Android Firefox phone' => [self::ANDROID_FIREFOX_PHONE, null, true],
            'client hint ?1 wins over a desktop UA' => [self::DESKTOP_CHROME, '?1', true],
            'Android Chrome tablet' => [self::ANDROID_CHROME_TABLET, null, false],
            'legacy iPad UA' => [self::IPAD_LEGACY, null, false],
            'iPadOS desktop-class UA' => [self::IPAD_DESKTOP_CLASS, null, false],
            'desktop Chrome' => [self::DESKTOP_CHROME, null, false],
            'empty UA' => ['', null, false],
            'absent UA' => [null, null, false],
            'client hint ?0 wins over a phone UA' => [self::ANDROID_CHROME_PHONE, '?0', false],
            'client hint present but not ?1 does not fall through to the UA' => [self::ANDROID_CHROME_PHONE, '', false],
        ];
    }

    #[DataProvider('clients')]
    public function test_matches(?string $userAgent, ?string $hint, bool $expected): void
    {
        // Request::create() fills HTTP_USER_AGENT with "Symfony", so absence has to be made explicit.
        $request = Request::create('/');
        $request->headers->remove('User-Agent');
        if ($userAgent !== null) {
            $request->headers->set('User-Agent', $userAgent);
        }
        if ($hint !== null) {
            $request->headers->set('Sec-CH-UA-Mobile', $hint);
        }

        $this->assertSame($expected, PhoneClient::matches($request));
    }
}
