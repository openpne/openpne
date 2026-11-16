<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Built per request rather than shipped as a file: RFC 9116 requires an Expires field, and a static
 * one goes stale on a site that is not redeployed.
 */
class SecurityTxtController extends Controller
{
    public const CONTACT = 'https://github.com/openpne/openpne/security/advisories/new';

    public const POLICY = 'https://github.com/openpne/openpne/blob/main/SECURITY.md';

    public function __invoke(): Response
    {
        $body = implode("\n", [
            'Contact: '.self::CONTACT,
            'Expires: '.Carbon::now()->utc()->addMonths(6)->format('Y-m-d\TH:i:s\Z'),
            'Policy: '.self::POLICY,
            'Preferred-Languages: en, ja',
        ])."\n";

        return response($body, Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
