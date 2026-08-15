<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * What an MCP token is made of. `mcp:read` is the endpoint's own gate — a token minted for some
 * other purpose cannot so much as list the tools — and `mcp:write` is asked again inside each tool
 * that writes, so a read-only token reaches the server and still cannot say anything.
 * See docs/internals/mcp.md.
 */
final class McpAbilities
{
    /** Stamped on every token minted for this endpoint, so a revocation can leave other tokens alone. */
    public const TOKEN_NAME = 'mcp';

    public const READ = 'mcp:read';

    public const WRITE = 'mcp:write';
}
