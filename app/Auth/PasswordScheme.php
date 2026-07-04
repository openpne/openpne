<?php

namespace App\Auth;

/**
 * How a stored password hash was produced. The column (`password_scheme`) is null for
 * the default scheme — bcrypt over the plaintext — and non-null only for transitional
 * forms that need different verification. Login clears the scheme by rehashing to the
 * default on the first successful authentication.
 */
enum PasswordScheme: string
{
    /**
     * bcrypt over the OpenPNE 3 bare-MD5 hex of the plaintext: bcrypt(md5($password)).
     * Produced by the upgrade's wrap pass (App\Upgrade\Runner\PasswordWrap) so no bare
     * MD5 survives at rest; verified as Hash::check(md5($attempt), $hash).
     */
    case Md5Bcrypt = 'md5_bcrypt';
}
