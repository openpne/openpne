<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

abstract class MemberTool extends McpTool
{
    protected const REFUSED = 'No such member — or not one you may see.';
}
