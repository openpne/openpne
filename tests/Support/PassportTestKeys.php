<?php

declare(strict_types=1);

namespace Tests\Support;

use Laravel\Passport\Passport;

/**
 * One RSA pair per test process, pointed at before the application boots: the OAuth routes exist
 * only when PassportKeys sees the pair, so a later call would leave them unregistered.
 */
final class PassportTestKeys
{
    private static ?string $dir = null;

    public static function ensure(): string
    {
        if (self::$dir === null) {
            $dir = sys_get_temp_dir().'/openpne-passport-keys-'.getmypid();
            if (! is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            if (! is_file($dir.'/oauth-private.key')) {
                $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
                openssl_pkey_export($pair, $private);
                file_put_contents($dir.'/oauth-private.key', $private);
                file_put_contents($dir.'/oauth-public.key', openssl_pkey_get_details($pair)['key']);
                chmod($dir.'/oauth-private.key', 0600);
                chmod($dir.'/oauth-public.key', 0600);
            }
            self::$dir = $dir;
            register_shutdown_function(static function () use ($dir): void {
                @unlink($dir.'/oauth-private.key');
                @unlink($dir.'/oauth-public.key');
                @rmdir($dir);
            });
        }

        Passport::loadKeysFrom(self::$dir);

        return self::$dir;
    }

    /** Points Passport at an empty directory, as a site that never ran passport:keys. */
    public static function absent(): void
    {
        Passport::loadKeysFrom(sys_get_temp_dir().'/openpne-passport-keys-none');
    }
}
