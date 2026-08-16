<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Support\Feature;
use Laravel\Mcp\Request;

/**
 * What the diary tools share on top of {@see McpTool}: diary's own refusal and its body cap.
 */
abstract class DiaryTool extends McpTool
{
    /** A diary that is not there, and one the caller may not read, answer the same. */
    protected const REFUSED = 'No such diary — or it is not yours to read.';

    /**
     * Bounded by bytes, not characters: a body lives in a TEXT column (65535 bytes) and MySQL
     * rejects anything longer at insert time. The same cap the compose form applies
     * (StoreDiaryRequest); comments are capped here too, where the web form leaves the column to
     * refuse them.
     */
    protected const BODY_MAX_BYTES = 65535;

    /** Diary switched off takes its tools with it, as talk's does ({@see TalkTool::shouldRegister()}). */
    public function shouldRegister(): bool
    {
        return Feature::Diary->enabled();
    }

    /**
     * A text argument as the web forms receive one: whitespace at either end gone, so text that is
     * nothing but whitespace is refused as empty rather than written blank. The direct tool path
     * meets no TrimStrings, so the contract lives where the text is read. Anything that is not a
     * string is left as it came for the `string` rule to refuse, rather than coerced into text
     * nobody wrote.
     */
    protected static function trimmed(Request $request, string $key): mixed
    {
        $value = $request->get($key);

        return is_string($value) ? trim($value) : $value;
    }
}
