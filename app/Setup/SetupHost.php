<?php

declare(strict_types=1);

namespace App\Setup;

/** What openpne:setup asks of the PHP it runs under. */
class SetupHost
{
    public function interactive(): bool
    {
        return defined('STDIN') && stream_isatty(STDIN);
    }

    /** Prompts runs stty, and setup runs itself again, through proc_open. */
    public function canSpawn(): bool
    {
        return function_exists('proc_open');
    }

    /** Symfony's hidden question, which Prompts falls back to without proc_open, runs stty through shell_exec. */
    public function hidesTypedInput(): bool
    {
        return function_exists('shell_exec');
    }

    /**
     * Runs a command on this terminal, handing it $lock, and waits for it.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     * @param  resource  $lock
     */
    public function spawn(array $command, array $environment, string $directory, mixed $lock): int
    {
        $process = proc_open($command, [STDIN, STDOUT, STDERR, SetupLock::DESCRIPTOR => $lock], $pipes, $directory, $environment);

        return is_resource($process) ? proc_close($process) : 1;
    }
}
