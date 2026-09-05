<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * `mcp:read` gates the endpoint; `mcp:write` is asked again inside each tool that writes.
 * See docs/internals/mcp.md "Tokens and abilities".
 */
final class McpAbilities
{
    /** Stamped on every token minted for this endpoint, so a revocation can leave other tokens alone. */
    public const TOKEN_NAME = 'mcp';

    public const READ = 'mcp:read';

    public const WRITE = 'mcp:write';
}
