<?php

declare(strict_types=1);

namespace App\Upgrade\Runner;

use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/** The one way the upgrade commands print a JSON document: raw (source text can hold `<fg=…>`), whatever the verbosity, invalid UTF-8 substituted. */
final class JsonOutput
{
    public static function encode(mixed $document): string
    {
        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    public static function write(Command $command, mixed $document): void
    {
        $command->getOutput()->write(self::encode($document), true, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);
    }
}
